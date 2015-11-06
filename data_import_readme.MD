wecenter问答数据批量导入
===========================

实现功能
-------------------------

通过Excel文件导入问答数据，Excel文件格式见 uploads/data_import/data_import_demo.xlsx

功能特点
--------------------------

* 问答添加的用户，从会员中随机抽取（group_id > 3 and group_id <=9)
* 问答的加入时间和更新时间，由导入页面给出的时间段中随机选择时间点
* 问答时间的先后顺序模拟现实
* 自动添加导入文件中问答的专题和话题，并做关联

安装和使用
---------------------------

* 将压缩包解压，直接考入wecenter的根目录即可
* 进入后台管理界面，找左侧导航栏最下面的“自定义工具”->“数据批量导入”

加入的文件结构
----------------------------

* system/config/admin_menu.php  - 后台管理页面导航菜单的配置
* system/PHPExcel    - PHPExcel库放置目录
* app/admin/data_import.php  - Controller
* views/default/admin/data_import.tpl.htm  - View 管理后台页面
* views/default/admin/data_import_progress.tpl.htm  - View 处理进程显示页面
* uploads/data_import   - 上传文件放置目录
* uploads/data_import/data_import_demo.xlsx - Excel演示文件格式 

修改的文件
-----------------------------

system/config/admin_menu.php  - 在文档底部加入代码实现后台管理页面左侧导航

```php
$config[] = array(
 	'title' => '自定义工具',
 	'cname' => 'tools',
 	'children' => array(
	    array(
	    'id' => 505,
	    'title' => '数据批量导入',
	    'url' => 'admin/data_import/',
	    ),
 	)
);
```

数据文件要求
---------------------

* 按照模板填写问答数据
* 目前只支持xls格式


Trouble Shooting
-------------------------

* 问题的add_time和update_time均模拟为随机时间，data import中在插入数据后对两个时间进行了更新，但对update_time的更新不成功

    * 原因分析：question的update_time在保存问题的答案时被model answer通过shutdown_update修改, 在model question析构时执行了shutdown_query，导致时间被改

    * 解决方法：在data_import中，在保存答案之后，调用model quesiton的shutdown_update而非update修改update_time

stolendust@126.com , 20140117