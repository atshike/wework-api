<?php

namespace Atshike\WeworkApi\Services;

use GuzzleHttp\Client;

/**
 * 企业内部开发.
 */
class WeWorkService
{
    private string $access_token;

    private string $cacke_key;

    public function __construct()
    {
        $this->cacke_key = 'wework_access_token';

        $access_token = cache($this->cacke_key);
        if (empty($access_token)) {
            $this->get_access_token();
        } else {
            $this->access_token = $access_token;
        }
    }

    // 获取 access_token
    private function get_access_token(array $config = []): void
    {
        $rs = $this->httpSend(
            '/cgi-bin/gettoken',
            'GET',
            [
                'corpid' => $config['wework_corpid'] ?? config('service.wework.corpid'),
                'corpsecret' => $config['wework_corpsecret'] ?? config('service.wework.corpsecret'),
            ],
        );

        $this->access_token = $rs['access_token'];
        cache([$this->cacke_key => $rs['access_token']], now()->addSeconds($rs['expires_in']));
    }

    public function responsePostCom($path, $method, $params = [])
    {
        $url_params = [
            'access_token' => $this->access_token,
        ];
        if ($method == 'GET') {
            $url_params = array_merge($params, $url_params);
        }
        $path = trim($path, '/');
        $url = "https://qyapi.weixin.qq.com/{$path}?".http_build_query($url_params);

        return $this->httpSend($url, $method, $params);
    }

    // 统一http
    public function httpSend($url, $method, $params = [])
    {
        $client = new Client();
        $res = $client->request($method, $url, $params);
        $rs = json_decode((string) $res->getBody(), true);

        if ($rs['errcode'] !== 0) {
            info(request()->path().'::'.json_encode($rs, JSON_UNESCAPED_UNICODE));
        }

        return $rs;
    }

    /**
     * 发送新客户欢迎语.
     * https://developer.work.weixin.qq.com/document/path/92137
     */
    public function welcome(string $welcome_code, array $wework_welcome, string $customer_name)
    {
        $data = [
            'welcome_code' => $welcome_code,
        ];
        if (! empty($wework_welcome['content'])) {
            $data['text'] = [
                'content' => str_replace('##客户名称##', $customer_name, $wework_welcome['content']),
            ];
        }
        $attachments = [];
        if (! empty($wework_welcome['image'])) {
            $attachments[] = [
                'msgtype' => 'image',
                'image' => [
                    // "media_id" => "MEDIA_ID",
                    'pic_url' => $wework_welcome['image'],
                ],
            ];
        }
        if (! empty($wework_welcome['mini_program'])) {
            // 企微绑定小程序，应用管理=>应用详情
            $miniprogram = $wework_welcome['mini_program'];
            $media = $this->uploadMaterial($miniprogram['pic_media_id']);

            $attachments[] = [
                'msgtype' => 'miniprogram',
                'miniprogram' => [
                    'title' => $miniprogram['title'],
                    'pic_media_id' => $media['media_id'],
                    'appid' => $miniprogram['appid'],
                    'page' => $miniprogram['page'],
                ],
            ];
        }
        $data['attachments'] = $attachments;

        return $this->httpSend(
            '/cgi-bin/externalcontact/send_welcome_msg',
            'POST',
            [
                'json' => $data,
            ],
        );
    }

    /**
     * 上传图片
     * https://developer.work.weixin.qq.com/document/path/90256
     */
    public function upload($file, $fileName)
    {
        return $this->httpSend(
            'cgi-bin/media/uploadimg',
            'post',
            [
                'multipart' => [
                    [
                        'name' => $fileName,
                        'contents' => fopen($file, 'r'),
                        'filename' => $fileName,
                    ],
                ],
            ]);
    }

    /**
     * 上传附件资源
     *
     * @param $media_type string 媒体文件类型，分别有图片（image）、视频（video）、普通文件（file）
     * @param $attachment_type string 附件类型 1：朋友圈；2:商品图册
     */
    public function upload_attachment($file, string $media_type, string $attachment_type)
    {
        return $this->httpSend(
            "https://qyapi.weixin.qq.com/cgi-bin/media/upload_attachment?access_token={$this->access_token}&media_type={$media_type}&attachment_type={$attachment_type}",
            'POST',
            [
                'headers' => [
                    'Content-Type' => 'multipart/form-data;',
                ],
                'multipart' => [
                    [
                        'contents' => fopen($file, 'r'),
                        'filename' => $file->getFilename(),
                        'filelength' => $file->getSize(),
                    ],
                ],
            ],
        );
    }

    /**
     * 上传临时素材
     * https://developer.work.weixin.qq.com/document/path/90253
     * 仅三天内有效
     */
    public function uploadMaterial($file)
    {
        return $this->httpSend(
            'https://qyapi.weixin.qq.com/cgi-bin/media/upload?access_token='.$this->access_token.'&type=image',
            'POST',
            [
                'headers' => [
                    'Content-type' => 'image/png',
                    'Content-Length' => $file->getSize(),
                ],
                'multipart' => [
                    [
                        'contents' => fopen($file, 'r'),
                        'filename' => $file->getFilename(),
                        'filelength' => $file->getSize(),
                    ],
                ],
            ],
        );
    }
}
