<?php

/*
 * Model查询处理
 */

namespace XiHuan\Crbac\Services;

use Closure,
    Request;
use Illuminate\Database\Eloquent\Model as Eloquent;

class ModelSelect {

    protected $modelClass; //要修改的Model类名
    protected $input; //要修改的请求数据
    protected $builder; //Illuminate\Database\Eloquent\Builder
    protected $orderKey = 'order'; //排序键名
    protected $byKey = 'by'; //排序类型键名

    /*
     * 作用：初始化
     * 参数：$model Model|string Model对象或Model类名
     *      $input array 要输入的参数，默认全部请求参数
     *      $default array 默认要输入的参数
     * 返回值：void
     */
    public function __construct($model, array $input = [], array $default = []) {
        if (is_string($model)) {
            $this->modelClass = $model;
        } elseif ($model instanceof Eloquent) {
            $this->modelClass = get_class($model);
        } else {
            throw new Exception('程序异常！');
        }
        foreach ($default as $key => $val) {
            Request::input($key) !== null || Request::merge([$key => $val]);
        }
        $this->input = $input? : Request::all();
        $this->builder = call_user_func($this->modelClass . '::query');
    }
    /*
     * 作用：调用 Builder 方法
     * 参数：$method string 方法名
     *      $parameters array 参数集
     * 返回值：$this
     */
    public function __call($method, $parameters) {
        call_user_func_array([$this->builder, $method], $parameters);
        return $this;
    }
    /*
     * 作用：添加where规则处理
     * 参数：$where array 规则数组
     * 返回值：$this
     */
    public function where(array $where) {
        return $this->rule($where, function($field, $operator, $val) {
                    if ($operator == 'not in') {//in查询处理
                        $this->builder->whereNotIn($field, $val);
                    } elseif ($operator == 'in') {//in查询处理
                        $this->builder->whereIn($field, $val);
                    }
                    $this->builder->where($field, $operator, $val);
                });
    }
    /*
     * 作用：添加having规则处理
     * 参数：$having array 规则数组
     * 返回值：$this
     */
    public function having(array $having) {
        return $this->rule($having, function($field, $operator, $val) {
                    $this->builder->having($field, $operator, $val);
                });
    }
    /*
     * 作用：添加order规则处理
     * 参数：$orderBy array 规则数组
     * 返回值：$this
     */
    public function order(array $orderBy) {
        $name = array_get($this->input, $this->orderKey);
        if ($name && $field = array_get($orderBy, $name)) {//存在排序字段名
            $by = array_get($this->input, $this->byKey); //取出排序值
            if (!in_array($by, ['desc', 'asc'], true)) {
                $by = 'desc';
                $this->input = array_merge($this->input, [$this->byKey => $by]);
            }
            $this->builder->orderBy($field, $by);
        }
        return $this;
    }
    /*
     * 作用：添加order规则处理
     * 参数：$orderBy string 设置排序键名
     *       $byKey string 设置排序类型键名
     * 返回值：void
     */
    public function setOrderKey($orderKey = 'order', $byKey = 'by') {
        $this->orderKey = $orderKey;
        $this->byKey = $byKey;
    }
    /*
     * 作用：获取分页串处理
     * 参数：$descClassName 倒序样式类名
     *       $ascClassName 正序样式类名
     * 返回值：Closure
     */
    public function orderToString($descClassName = 'order-desc', $ascClassName = 'order-asc') {
        $input = $this->input;
        array_forget($input, [$this->orderKey, $this->byKey]);
        $query = http_build_query($input);
        return function($name, $getUrl = true, $defaultBy = 'desc') use($query, $descClassName, $ascClassName) {
            $by = null;
            if (isset($this->input[$this->orderKey]) && $this->input[$this->orderKey] === $name && isset($this->input[$this->byKey])) {
                $by = $this->input[$this->byKey];
            }
            if (!in_array($by, ['desc', 'asc'], true)) {
                $by = $defaultBy;
            }
            if ($getUrl) {
                return Request::url() . ($query ? '?' . $query . '&' : '?') . http_build_query([$this->orderKey => $name, $this->byKey => $by == 'asc' ? 'desc' : 'asc']);
            } else {
                return $by == 'asc' ? $ascClassName : $descClassName;
            }
        };
    }
    /*
     * 作用：规则处理
     * 参数：$rules array 规则
     *      $callback Closure
     * 返回值：$this
     */
    protected function rule(array $rules, Closure $callback) {
        foreach ($rules as $field => $rule) {
            if (is_string($rule)) {
                if (is_int($field)) {//直接=处理
                    $field = $rule;
                    $rule = null;
                } else {
                    $rule = [1 => $rule];
                }
            }
            $val = array_get($this->input, $field);
            if (empty($val)) {
                continue;
            }
            if (is_callable($rule)) {//加高处理
                $rule($this->builder, $val);
                continue;
            }
            if (isset($rule[0])) {//字段名
                $field = $rule[0];
            }
            if (isset($rule[1])) {//条件
                $operator = $rule[1];
            } else {
                $operator = '=';
            }
            if (isset($rule[2]) && is_callable($rule[2])) {//回调值处理
                $val = $rule[2]($val);
            }
            if ($operator == 'like') {//like查询处理
                $val = '%' . $val . '%';
            }
            $callback($field, $operator, $val);
        }
        return $this;
    }
    /*
     * 作用：修改数据
     * 参数：$callback Closure
     *       $perPage int|null 每页条数
     *       $perPageColumns array 分页取字段集
     * 返回值：Illuminate\Pagination\AbstractPaginator
     */
    public function lists(Closure $callback = null, $perPage = 0, $perPageColumns = array('*')) {
        if ($callback) {
            $callback($this->builder);
        }
        if (is_null($perPage)) {
            return $this->builder->get();
        }
        if ($perPage < 1) {
            $perPage = $this->builder->getModel()->getPerPage();
        }
        if ($this->builder->getQuery()->groups) {
            $Bindings = $this->builder->getQuery()
                    ->getConnection()
                    ->prepareBindings($this->builder->getBindings());
            $BuilderPage = clone $this->builder;
            $BuilderPage->getQuery()->orders = null; //去掉无意义的排序
            $total = \DB::Connection($this->builder->getModel()->getConnectionName())->select('select count(1) as num from (' . $BuilderPage->select($perPageColumns)->toSql() . ') as t', $Bindings)[0]->num; //取出总记录数
            $paginator = $this->builder->getQuery()->getConnection()->getPaginator();
            $page = $paginator->getCurrentPage($total);
            $results = $this->builder->forPage($page, $perPage)->get($perPageColumns);
            $lists = $paginator->make($results, $total, $perPage);
        } else {
            $lists = $this->builder->paginate($perPage, $perPageColumns);
        }
        return $lists->appends(Request::all());
    }
}
