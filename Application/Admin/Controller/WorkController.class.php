<?php
namespace Admin\Controller;
use Think\Controller;

class WorkController extends CommonController
{
	public function _initialize()
	{
		parent::_initialize();
	}

	/**
	 * show available works for member, condition:
	 *
	 * 1. s_time <= today
	 * 2. member_uid = user_id
	 * 3. status != 4
	 *
	 * @access member
	 */
	public function schedule()
	{
		if (! $this->is_member) {
			$this->redirect('admin/error/deny');
		}

		$pageno   = I('get.p', 1, 'intval');
        $pagesize = C('pagesize');
        $limit    = $pageno . ',' . $pagesize;

		$uid 	  = $this->uid;
		$username = $this->username;
		$time     = time();

		$where = array();
		$where = array(
			'member_uid' => array('EQ', $uid),
			's_time'	 => array('ELT', $time),
			'status'     => array('NEQ', 4)
		);

		$workModel = M('work');
		$workArr   = $workModel
			->where($where)
			->order('status asc, s_time desc, id asc')
			->page($limit)
			->select();

		$total = $workModel->where($where)->count();
        $Page  = new \Think\Page($total, $pagesize);
        $Page->setConfig('prev', '&laquo;上一页');
        $Page->setConfig('next', '下一页&raquo;');
        $show  = $Page->show();

        $startable_num = get_startable_works_num($uid);
		$summariable_num = get_finished_works_num($uid);

		$leaderIdsList = get_level_uids_list(array('truename'), 1);

		$projIds = makeImplode($workArr, 'project_id');
		$projectModel = M('project');
		$projectArr   = $projectModel
			->field('id, project_name, status')
			->where(array('id' => array('IN', "$projIds")))
			->select();
		$projectIdsList = makeIndex($projectArr, 'id');

		$data = array();
		foreach ($workArr as $work) {
			$project_id = $work['project_id'];

			$project_status = isset($projectIdsList[$project_id]) ? $projectIdsList[$project_id]['status'] : 3;
			if (intval($project_status) == 3) {
				continue;
			}

			$work['project_name'] = '';
			if (isset($projectIdsList[$project_id])) {
				$work['project_name'] = $projectIdsList[$project_id]['project_name'];
			}

			$leader_uid = $work['leader_uid'];
			$work['leader_truename'] = '';
			if (isset($leaderIdsList[$leader_uid])) {
				$work['leader_truename'] = $leaderIdsList[$leader_uid]['truename'];
			}

			$data[] = $work;
		}

        $this->assign('data',    $data);
        $this->assign('show',    $show);
        $this->assign('pagenum', $Page->totalPages);
        $this->assign('index',   $Page->firstRow+1);
		$this->assign('startable_num', $startable_num);
        $this->assign('summariable_num', $summariable_num);
		$this->display();
	}

	/**
	 * @access member
	 */
	public function start()
	{
		if (! $this->is_member) {
			$this->redirect('admin/error/deny');
		}

		$id = I('get.id', 0, 'intval');
		if ($id === 0) {
			alert_back('参数错误！');
		}

		$workModel = M('work');
		$workArr   = $workModel->where(array('id' => $id))->find();

		$userModel    = M('user');
		$projectModel = M('project');

		$data = array();
		$leader_truename = $userModel
			->where(array('id' => $workArr['leader_uid']))
			->getField('truename');

		$member_truename = $userModel
			->where(array('id' => $workArr['member_uid']))
			->getField('truename');

		$project_name = $projectModel
			->where(array('id' => $workArr['project_id']))
			->getField('project_name');

		$data = $workArr;
		$data['member_truename'] = $member_truename;
		$data['leader_truename'] = $leader_truename;
		$data['project_name']    = $project_name;
		$data['work_id']         = $workArr['id'];

		$this->assign('data', $data);
		$this->display();
	}

	/**
	 * @access member
	 */
	public function startHandle()
	{
		if (! IS_POST) {
			$this->redirect('admin/index/index');
		}

		if (! $this->is_member) {
			$this->redirect('admin/error/deny');
		}

		$work_id    = I('post.work_id',    0, 'intval');
		$project_id = I('post.project_id', 0, 'intval');
		$member_uid = I('post.member_uid', 0, 'intval');
		$leader_uid = I('post.leader_uid', 0, 'intval');

		if ($work_id === 0
			|| $project_id === 0
			|| $member_uid === 0
			|| $leader_uid === 0)
		{
			alert_back('表单数据错误！');
		}

		$task_arr = I('post.task');
		$data = array();
		foreach ($task_arr as $task) {

			$tmp = array();

			$tmp['task_name']  = trim($task['task_name']);
			$tmp['remark']     = trim($task['remark']);
			$tmp['s_time']     = strtotime($task['s_time'] . ' 00:00:00');
			$tmp['e_time']     = strtotime($task['e_time'] . ' 23:59:59');

			$tmp['work_id']    = $work_id;
			$tmp['project_id'] = $project_id;
			$tmp['member_uid'] = $member_uid;
			$tmp['leader_uid'] = $leader_uid;
			$tmp['c_time']     = time();

			$data[] = $tmp;
		}

		$task_model = M('task');
		$add_res = $task_model->addAll($data);

		if ($add_res === false) {
			alert_back('数据写入失败！');
		}

		$workModel  = M('work');
		$update_data = array('status' => 1);
		$update_res = $workModel->where(array('id' => $work_id))->save($update_data);

		if ($update_res === false) {
			alert_back('更新工作状态失败！');
		}

		alert_back('划分日常任务成功！');
	}

	/**
	 * @access member
	 */
	public function edit()
	{
		if (! $this->is_member) {
			$this->redirect('admin/error/deny');
		}

		$id = I('id', 0, 'intval');
		if ($id === 0) {
			alert_back('参数错误！');
		}

		$workModel = M('work');
		$workArr = $workModel->where(array('id' => $id))->find();

		$project_id = $workArr['project_id'];
		$member_uid = $workArr['member_uid'];
		$leader_uid = $workArr['leader_uid'];

		$projectModel = M('project');
		$project_name = $projectModel->where(array('id' => $project_id))->getField('project_name');

		$userModel = M('user');
		$leader_truename = $userModel->where(array('id' => $leader_uid))->getField('truename');

		$data = array();
		$data = array(
			'work_id'         => $id,
			'project_id'      => $project_id,
			'project_name'    => $project_name,
			'work_name'       => $workArr['work_name'],
			'member_uid'      => $member_uid,
			'leader_uid'      => $leader_uid,
			'leader_truename' => $leader_truename,
			's_time'          => $workArr['s_time'],
			'e_time'          => $workArr['e_time']
		);

		$task_where = array(
			'member_uid' => $member_uid,
			'leader_uid' => $leader_uid,
			'work_id'    => $id,
			'project_id' => $project_id
		);

		$taskModel = M('task');
		$taskArr = $taskModel->where($task_where)->select();

		$this->assign('data', $data);
		$this->assign('task_data', $taskArr);
		$this->display();
	}

	/**
	 * @access member
	 */
	public function editHandle()
	{
		if (! IS_POST) {
			$this->redirect('admin/index/index');
		}

		if (! $this->is_member) {
			$this->redirect('admin/error/deny');
		}
		$work_id 	= I('post.work_id',    0, 'intval');
		$project_id = I('post.project_id', 0, 'intval');
		$member_uid = I('post.member_uid', 0, 'intval');
		$leader_uid = I('post.leader_uid', 0, 'intval');

		if ($work_id === 0 || $project_id === 0 || $member_uid === 0
			|| $leader_uid === 0)
		{
			alert_back('表单数据不完整！');
		}

		$where = array(
			'member_uid' => $member_uid,
			'leader_uid' => $leader_uid,
			'work_id'    => $work_id,
			'project_id' => $project_id
		);

		$taskModel = M('task');

		// step 1: clear old task data
		$delete_res = $taskModel->where($where)->delete();
		if ($delete_res === false) {
			alert_back('清空旧数据失败！');
		}

		// step 2: add new task data
		$task_data = $_POST['task'];
		$add_data = array();
		foreach ($task_data as $task) {
			$tmp = array();

			$tmp['task_name']  = trim($task['task_name']);
			$tmp['remark']     = text_display(trim($task['remark']));
			$tmp['s_time']     = strtotime($task['s_time'] . ' 00:00:00');
			$tmp['e_time']     = strtotime($task['e_time'] . ' 23:59:59');

			$tmp['work_id']    = $work_id;
			$tmp['project_id'] = $project_id;
			$tmp['member_uid'] = $member_uid;
			$tmp['leader_uid'] = $leader_uid;
			$tmp['c_time']     = time();

			$add_data[] = $tmp;
		}

		$add_res = $taskModel->addAll($add_data);
		if ($add_res === false) {
			alert_back('添加新任务失败！');
		}

		alert_back('重新分配工作成功！');
	}

	/**
	 * @access ALL
	 */
	public function view()
	{
		$id = I('get.id', 0, 'intval');
		if ($id === 0) {
			alert_back('参数错误！');
		}

		$projectModel = M('project');
		$workModel    = M('work');
		$taskModel    = M('task');
		$userModel    = M('user');

		$workArr = $workModel->where(array('id' => $id))->find();
		if ($workArr === false) {
			alert_back('结果集为空！');
		}

		$project_name = $projectModel->where(array('id' => $workArr['project_id']))->getField('project_name');

		$member_truename = $userModel->where(array('id' => $workArr['member_uid']))->getField('truename');
		$leader_truename = $userModel->where(array('id' => $workArr['leader_uid']))->getField('truename');

		$work_info = array();
		$work_info = $workArr;

		$work_info['project_name']    = $project_name;
		$work_info['member_truename'] = $member_truename;
		$work_info['leader_truename'] = $leader_truename;

		$taskArr = $taskModel->where(array('work_id' => $id))->select();

		$task_data = array();
		$task_data = $taskArr;

		$this->assign('work_info', $work_info);
		$this->assign('task_data', $task_data);
		$this->display();
	}
}
