<?php

namespace QuarkPlugin;

use think\facade\Db;
use think\facade\Request;
use think\Exception;
use app\model\Source as SourceModel;
use app\model\SourceLog as SourceLogModel;

class QuarkPlugin
{
    protected $url;
    protected $model;
    protected $SourceLogModel;

    public function __construct()
    {
        // 第三方转存接口地址
        $this->url = "https://api.kuleu.com/api";
        $this->model = new SourceModel();
        $this->SourceLogModel = new SourceLogModel();
        $this->source_category_id = 0;
    }

    public function getFiles($type=0,$pdir_fid=0)
    {
        $transfer = new \netdisk\Transfer();
        return $transfer->getFiles($type,$pdir_fid);
    }
    
    public function import($allData, $source_category_id)
    {
        $this->source_category_id = $source_category_id;

        $length = count($allData);
        $logId = $this->SourceLogModel->addLog('批量转入链接', $length);

        foreach ($allData as $data) {
            $this->processSingleData($data, $logId, $length, 1);
        }

        $this->SourceLogModel->editLog($logId, $length, '', '', 3);
    }

    public function transfer($allData, $source_category_id)
    {
        $this->source_category_id = $source_category_id;

        $length = count($allData);
        $logId = $this->SourceLogModel->addLog('批量转存他人链接', $length);

        foreach ($allData as $data) {
            $this->processSingleData($data, $logId, $length);
        }

        $this->SourceLogModel->editLog($logId, $length, '', '', 3);
    }

    public function transferAll($source_category_id, $day = 0)
    {
        if(empty($this->url)){
            return jerr('未配置转存接口地址');
        }

        @set_time_limit(999999);
        
        $this->source_category_id = $source_category_id;

        // 使用新的影视API接口
        $apiUrl = $this->url . "/yingshi?quark";
        $res = curlHelper($apiUrl, "GET", [])['body'];
        $res = json_decode($res, true);

        if ($res['code'] !== 1 || empty($res['data'])) {
            return jerr('接口返回数据为空');
        }

        $allData = [];
        $logId = '';
        
        // 处理返回的数据
        foreach ($res['data'] as $item) {
            $allData[] = [
                'title' => $item['name'],
                'url' => $item['viewlink'],
                'addtime' => $item['addtime'],
                'source_category_id' => $this->source_category_id
            ];
        }

        if (count($allData) > 0) {
            $name = $day == 2 ? '每日更新' : '全部转存';
            $logId = $this->SourceLogModel->addLog($name, count($allData));
        } else {
            return jerr('接口返回数据为空');
        }

        foreach ($allData as $data) {
            $this->processSingleData($data, $logId, count($allData));
        }

        $this->SourceLogModel->editLog($logId, count($allData), '', '', 3);
        
        return jok('资源更新成功，共更新' . count($allData) . '条记录');
    }

    function processSingleData($value, $logId = 0, $total_result = 0, $isType = 0)
    {
        // 检查标题或URL是否已存在，避免重复
        $detail = $this->model->where('title', $value['title'])->find();
        if (!empty($detail)) {
            if (!empty($logId)) {
                $this->SourceLogModel->editLog($logId, $total_result, 'skip_num', '重复跳过转存');
            }
            return;
        }

        $url = $value['url'];
        $substring = strstr($url, 's/');
        if ($substring === false) {
            if (!empty($logId)) {
                $this->SourceLogModel->editLog($logId, $total_result, 'fail_num', '资源地址格式有误');
            }
            return;
        }

        $urlData = [
            'expired_type' => 1,  // 1正式资源 2临时资源
            'url' => $url,
            'code' => $value['code'] ?? '',
            'isType' => $isType
        ];

        $transfer = new \netdisk\Transfer();
        $res = $transfer->transfer($urlData);

        if ($res['code'] !== 200) {
            if (!empty($logId)) {
                $this->SourceLogModel->editLog($logId, $total_result, 'fail_num', $res['message']);
            }
            return;
        }

        $title = $value['title'] ? $value['title'] : preg_replace('/^\d+\./', '', $res['data']['title']);
        $source_category_id = $value['source_category_id'] ?? $this->source_category_id;

        $data = [
            "title" => $title,
            "url" => $res['data']['share_url'] ?? $url,
            "is_type" => determineIsType($res['data']['share_url'] ?? $url),
            "code" => $res['data']['code'] ?? $value['code'] ?? '',
            "source_category_id" => $source_category_id,
            "update_time" => time(),
            "create_time" => time(),
            "fid" => is_array($res['data']['fid'] ?? '') ? json_encode($res['data']['fid']) : ($res['data']['fid'] ?? '')
        ];

        $this->model->insertGetId($data);
        if (!empty($logId)) {
            $this->SourceLogModel->editLog($logId, $total_result, 'new_num', '');
        }
    }
}
