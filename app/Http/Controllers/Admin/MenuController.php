<?php
/**
 * Date: 2019/2/25 Time: 14:49
 *
 * @author  Eddy <cumtsjh@163.com>
 * @version v1.0.0
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MenuRequest;
use App\Model\Admin\Menu;
use App\Repository\Admin\MenuRepository;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Str;
use Log;
use Route;

class MenuController extends Controller
{
    protected $formNames = ['name', 'pid', 'status', 'order', 'route', 'group', 'remark', 'url'];

    public function __construct()
    {
        parent::__construct();

        $this->breadcrumb[] = ['title' => '菜单管理', 'url' => route('admin::menu.index')];
    }

    /**
     * 菜单管理-菜单列表
     *
     */
    public function index()
    {
        $this->breadcrumb[] = ['title' => '菜单列表', 'url' => ''];
        return view('admin.menu.index', ['breadcrumb' => $this->breadcrumb]);
    }

    /**
     * 菜单管理-菜单列表数据
     *
     * @param Request $request
     */
    public function list(Request $request)
    {
        $perPage = (int) $request->get('limit', 50);
        $action = $request->get('action');
        $condition = $request->only($this->formNames);

        if (isset($condition['pid'])) {
            $condition['pid'] = ['=', $condition['pid']];
        } else {
            if ($action !== 'search') {
                $condition['pid'] = ['=', 0];
            }
        }

        $data = MenuRepository::list($perPage, $condition);

        return $data;
    }

    /**
     * 菜单管理-新增菜单
     *
     */
    public function create()
    {
        //dd(\App\Repository\Admin\MenuRepository::tree());
        $this->breadcrumb[] = ['title' => '新增菜单', 'url' => ''];
        return view('admin.menu.add', ['breadcrumb' => $this->breadcrumb]);
    }

    /**
     * 菜单管理-保存菜单
     *
     * @param MenuRequest $request
     * @return array
     */
    public function save(MenuRequest $request)
    {
        try {
            MenuRepository::add(array_merge($request->only($this->formNames), ['guard_name' => 'admin']));
            return [
                'code' => 0,
                'msg' => '新增成功',
                'redirect' => route('admin::menu.index')
            ];
        } catch (QueryException $e) {
            return [
                'code' => 1,
                'msg' => '新增失败：' . (Str::contains($e->getMessage(), 'Duplicate entry') ? '当前菜单已存在' : '其它错误'),
                'redirect' => route('admin::menu.index')
            ];
        }
    }

    /**
     * 菜单管理-编辑菜单
     *
     * @param int $id
     */
    public function edit($id)
    {
        $this->breadcrumb[] = ['title' => '编辑菜单', 'url' => ''];

        $model = MenuRepository::find($id);
        return view('admin.menu.add', ['id' => $id, 'model' => $model, 'breadcrumb' => $this->breadcrumb]);
    }

    /**
     * 菜单管理-更新菜单
     *
     * @param MenuRequest $request
     * @param int $id
     */
    public function update(MenuRequest $request, $id)
    {
        $data = $request->only($this->formNames);
        if (!isset($data['status'])) {
            $data['status'] = Menu::STATUS_DISABLE;
        }

        try {
            MenuRepository::update($id, $data);
            return [
                'code' => 0,
                'msg' => '编辑成功',
                'redirect' => route('admin::menu.index')
            ];
        } catch (QueryException $e) {
            return [
                'code' => 1,
                'msg' => '编辑失败：' . (Str::contains($e->getMessage(), 'Duplicate entry') ? '当前菜单已存在' : '其它错误'),
                'redirect' => route('admin::menu.index')
            ];
        }
    }

    /**
     * 菜单管理-删除菜单
     *
     * @param Request $request
     * @param int $id
     */
    public function delete(Request $request, $id)
    {
        MenuRepository::delete($id);
        return [
            'code' => 0,
            'msg' => '删除成功',
            'redirect' => route('admin::menu.index')
        ];
    }

    /**
     * 菜单管理-自动更新菜单
     *
     * @return array
     * @throws \ReflectionException
     */
    public function discovery()
    {
        $addNum = 0;
        $updateNum = 0;

        foreach (Route::getRoutes()->getRoutesByName() as $k => $v) {
            if (Str::startsWith($k, 'admin::')) {
                // 取方法的第一行注释作为菜单的名称、分组名。格式：分组名称-菜单名称。未写分组名称，则注释直接作为菜单名称。未写注释则选用uri作为菜单名称。
                $action = explode('@', $v->getActionName());
                if (!method_exists($action[0], $action[1])) {
                    continue;
                }
                $reflection = new \ReflectionMethod($action[0], $action[1]);
                $comment = trim(array_get(explode("\n", $reflection->getDocComment()), 1, ''), " \t\n\r\0\x0B*");
                if ($comment === '') {
                    $data['name'] = $v->uri;
                    $data['group'] = '';
                } else {
                    if (Str::contains($comment, '-')) {
                        $arr = explode('-', $comment);
                        $data['name'] = trim($arr[1]);
                        $data['group'] = trim($arr[0]);
                    } else {
                        $data['name'] = trim($comment);
                        $data['group'] = '';
                    }
                }

                $data['route'] = $k;
                $data['guard_name'] = 'admin';
                if (in_array('GET', $v->methods) && !Str::contains($v->uri, '{')) {
                    $data['status'] = Menu::STATUS_ENABLE;
                } else {
                    $data['status'] = Menu::STATUS_DISABLE;
                }
                try {
                    $data['url'] = route($k, [], false);
                } catch (UrlGenerationException $e) {
                    $data['url'] = '';
                }

                $model = MenuRepository::exist($k);
                if ($model) {
                    if ($model->name != $data['name'] && $model->group != $data['group']) {
                        unset($data['status']);
                        MenuRepository::update($model->id, $data);
                        $updateNum++;
                    }
                } else {
                    MenuRepository::add($data);
                    $addNum++;
                }
            }
        }

        return [
            'code' => 0,
            'msg' => "更新成功。新增菜单数：{$addNum}，更新菜单数：{$updateNum}。",
            'redirect' => route('admin::menu.index')
        ];
    }

    /**
     * 菜单管理-批量操作
     */
    public function batch(Request $request)
    {
        $type = $request->input('type', '');
        $ids = $request->input('ids');
        if (!is_array($ids)) {
            return [
                'code' => 1,
                'msg' => '参数错误'
            ];
        }
        $ids = array_map(function ($item) {
            return intval($item);
        }, $ids);

        switch ($type) {
            case 'disable':
                Menu::query()->whereIn('id', $ids)->update(['status' => Menu::STATUS_DISABLE]);
                break;
            case 'enable':
                Menu::query()->whereIn('id', $ids)->update(['status' => Menu::STATUS_ENABLE]);
                break;
            case 'delete':
                Menu::query()->whereIn('id', $ids)->delete();
                break;
            case 'parent':
                $pid = intval($request->input('params', -1));
                if ($pid < 0 || ($pid > 0 && !MenuRepository::find($pid))) {
                    return [
                        'code' => 2,
                        'msg' => '父级菜单ID错误'
                    ];
                }
                if (in_array($pid, $ids)) {
                    return [
                        'code' => 3,
                        'msg' => '不能将父级菜单指定为自身'
                    ];
                }
                Menu::query()->whereIn('id', $ids)->update(['pid' => $pid]);
                break;
            default:
                break;
        }

        return [
            'code' => 0,
            'msg' => '操作成功',
            'reload' => true
        ];
    }
}
