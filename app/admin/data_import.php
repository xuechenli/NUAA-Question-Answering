<?php
/*
+--------------------------------------------------------------------------
|   WeCenter [#RELEASE_VERSION#]
|   ========================================
|   by stolendust@126.com 20140115
|   for importing questions and answers with Excel file
|   todo://
|       * update_time of question, model->shutdown_update() over model->update()
+---------------------------------------------------------------------------
*/

if (!defined('IN_ANWSION'))
{
    die;
}

class data_import extends AWS_ADMIN_CONTROLLER
{
    private $row_count;
    private $uid_list;
    private $last_question_id_before_import;

    //render page template
    private function render($error_msg = null)
    {
        $this->crumb(AWS_APP::lang()->_t('数据批量导入'), 'admin/data_import/');
        TPL::assign('menu_list', $this->model('admin')->fetch_menu_list(505));
        TPL::assign('error_msg', $error_msg);
        TPL::output('admin/data_import');
        exit;
    }

    //flush message to client end
    private function report_progress($message){
        echo $message;
        ob_flush(); //此句不能少
        flush();
    }

    //RETURN: a random user id
    private function get_random_uid($uid_excluded = null){
        if(empty($this->uid_list)){
            $users_list = $this->model('account')->get_users_list('( group_id > 100 and group_id <= 101)', 100); //fetch out member only
            foreach ($users_list as $key => $val){
                $this->uid_list[] = $val['uid'];
            }
        }

        //try some times to find an uid which is not $uid_excluded
        for($i = 0; $i < 2; $i++){
            $uid = $this->uid_list[array_rand($this->uid_list)];
            if($uid != $uid_excluded){
                return $uid;
            }
        }
        return $this->uid_list[0];
    }

    //delete old question with same content
    private function delete_same_question($question_content){
        $model = $this->model('question');
        if(! $this->last_question_id_before_import){
            $this->last_question_id_before_import = $model->max('question','question_id');
            $this->report_progress('max_question_id='.$this->last_question_id_before_import);
        }

        $id_list = $model->query_all('SELECT question_id FROM ' . $this->get_table('question') . ' WHERE question_id <= ' . intval($this->last_question_id_before_import) . ' AND question_content = "' . $question_content . '"');
        foreach($id_list as $question_id){
            $model->remove_question($question_id);
            $this->report_progress('[d'.$question_id.']');
        }
    }

    //RETURN: a random add_time
    private function update_add_time($model_name, $id, $add_time_start, $add_time_end){
        $add_time = mt_rand($add_time_start, $add_time_end);
        $model = $this->model($model_name);
        $value_list = array('add_time' => $add_time);
        $model->update($model_name, $value_list, $model_name . '_id =' . intval($id));
        return $add_time;
    }

    //RETURN: a feature id for $feature_title
    private function get_feature_id($feature_title){
        $feature_id = null;
        if(!empty($feature_title)){
            $model_feature = $this->model('feature');
            $feature = $model_feature->get_feature_by_title($feature_title);
            if(empty($feature)) {
                $feature_id = $model_feature->add_feature($feature_title);
            }else{
                $feature_id = $feature['id'];
            }
        }
        return $feature_id;
    }

    private function import_row($sheet, $row_index, $add_time_start, $add_time_end, $is_delete_same_question=true){
        $model = $this->model('publish');
        $uid_ask = $this->get_random_uid();
        $question_content = $sheet->getCell('B'.$row_index)->getValue();
        if(empty($question_content)) return;

        if($is_delete_same_quesiton){
            $this->delete_same_question($question_content);
        }

        //handle feature and topics
        $feature_title = $sheet->getCell('D'.$row_index)->getValue();
        $list = explode(',', str_replace('，',',',$sheet->getCell('E'.$row_index)->getValue()));
        $topic_list = array();
        //filter empty topics out
        foreach($list as $key => $topic_title){
            $title = trim($topic_title);
            if(!empty($title)){
                $topic_list[$key] = $topic_title;
            }
        }
        // add category
        $category_id = $sheet->getCell('J'.$row_index)->getValue();
        //publish question and add topics
        $question_id = $model->publish_question(
                $question_content,
                $sheet->getCell('C'.$row_index)->getValue(),
                $category_id,// 分类 ID
                // 不设置分类就默认为 1,
                $uid_ask,
                $topic_list);

        //add topic to feature
        $feature_id = $this->get_feature_id($feature_title);
        foreach($topic_list as $key => $topic_title){
            $topic_id = $this->model('topic')->get_topic_id_by_title($topic_title);
            $this->model('feature')->add_topic($feature_id, $topic_id);
        }

        //publish answers
        $add_time = $this->update_add_time('question', $question_id, $add_time_start, $add_time_end);

        for($column = 'G'; $column <= 'I'; $column ++){
            $answer_content = $sheet->getCell($column.$row_index)->getValue();
            if(!empty($answer_content)){
                $uid_answer = $this->get_random_uid($uid_ask);
                $answer_id = $model->publish_answer(
                        $question_id,
                        $answer_content,
                        $uid_answer);
                $add_time = $this->update_add_time('answer', $answer_id, $add_time, $add_time_end);
            }
        }

        $ret = $this->model('question')->shutdown_update('question', array('update_time' => $add_time), 'question_id =' . intval($question_id));
    }

    private function do_import($file_path, $file_ext = '.xls', $add_time_start, $add_time_end, $is_delete_same_question=true){
        if(! is_file($file_path)){
            throw new Zend_Exception('file does not exist:'.$file_path);
        }

        require_once(AWS_PATH.'PHPExcel/PHPExcel/IOFactory.php');

        if($file_ext==".xlsx"){
            $reader = PHPExcel_IOFactory::createReader('Excel2007');
        }else{
            $reader = PHPExcel_IOFactory::createReader('Excel5');
        }
        $reader->setLoadAllSheets();
        $reader->setReadDataOnly(true);
        $objExcel = $reader->load($file_path);

        $this->row_count = 0;
        $sheet_count = $objExcel->getSheetCount();

        for($index = 0; $index < $sheet_count; $index++){
            $sheet = $objExcel->getSheet($index);
            $this->report_progress('导入第'.$index. '页[' . $sheet->getTitle() .'] ');
            $sheet_row_count = $sheet->getHighestRow();
            //first row is title, ignored. import from the secend row.
            for($row_index = 2; $row_index <= $sheet_row_count; $row_index++){
                $this->import_row($sheet, $row_index, $add_time_start, $add_time_end, $is_delete_same_question);
                $this->row_count ++;
                if($row_index % 10 == 0){
                    $this->report_progress('.');
                }
            }
            $this->report_progress(' ' . ($sheet_row_count - 1) . ' 个问题被导入' . '<br/>');
        }
    }

    public function setup()
    {
        @set_time_limit(0);
    }

    public function index_action()
    {
        $this->render();
    }

    public function upload_and_import_action()
    {
        //upload file and verify file
        if(! $_FILES['datafile']['name']) {
            $this->render(AWS_APP::lang()->_t('未选择文件, 请选择上传文件'));
        }

        AWS_APP::upload()->initialize(array(
                    'allowed_types' => 'xls,xlsx',
                    'upload_path' => get_setting('upload_dir').'/data_import',
                    ))->do_upload('datafile');

        if (AWS_APP::upload()->get_error()){
            switch (AWS_APP::upload()->get_error()){
                case 'upload_invalid_filetype':
                    $this->render(AWS_APP::lang()->_t('文件类型无效, 请上传XLS或XLSX文件'));
                    break;
                default:
                    $this->render(AWS_APP::lang()->_t('错误代码') . ': ' . AWS_APP::upload()->get_error());
                    break;
            }
        }

        if (! $upload_data = AWS_APP::upload()->data()){
            $this->render(AWS_APP::lang()->_t('上传失败, 请与管理员联系'));
        }

        //render process page
        $this->crumb(AWS_APP::lang()->_t('数据批量导入'), 'admin/data_import/');
        TPL::assign('menu_list', $this->model('admin')->fetch_menu_list(505));
        TPL::output('admin/data_import_process');
        $this->report_progress('文件上传完成' . '<hr/>');

        $is_delete_same_question = true;
        if(!empty($_POST['is_clear_old_data'])){
            //delete all questions
            $this->report_progress('正在删除现有问答数据 ...');
            $model = $this->model('question');
            $count = 0;
            while($question_id = $model->fetch_one('question','question_id')){
                $model->remove_question($question_id);
                $count ++;
                if($count % 10 == 0){
                    $this->report_progress('.');
                }
            }
            $this->report_progress( $count. '条数据被清除'  . '<hr/>');
            $is_delete_same_question = false;
        }

        //import data
        $this->do_import($upload_data['full_path'], $upload_data['file_ext'], strtotime($_POST['add_time_start']), strtotime($_POST['add_time_end']), $is_delete_same_question);
        $this->report_progress('<hr/>'.'全部完成 -  共导入' . $this->row_count . '条数据' . '<br/>');
        ob_end_flush();
    }
}
