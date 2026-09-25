<?php
/**
 * @Author: fuukei
 * @Date:   2022-03-13 18:16:15
 * @Last Modified by: nicocatxzc
 * @Last Modified time: 2025-01-15 11:25:30
 */


/**
 * Classes
 */
include_once('classes/Cache.php');
include_once('classes/Images.php');
include_once('classes/gallery.php');
include_once('classes/QQ.php');
include_once('classes/Captcha.php');
use Sakura\API\QQ;
use Sakura\API\Cache;
use Sakura\API\Captcha;

/**
 * Router
 */
add_action('rest_api_init', function () {
    register_rest_route('sakura/v1', '/image/upload', array(
        'methods' => 'POST',
        'callback' => 'upload_image',
        'permission_callback' => '__return_true'
    )
    );
    register_rest_route('sakura/v1', '/cache_search/json', array(
        'methods' => 'GET',
        'callback' => 'cache_search_json',
        'permission_callback' => '__return_true'
    )
    );
    register_rest_route('sakura/v1', '/gallery', array(
        'methods' => 'GET',
        'callback' => [new \Sakura\API\gallery(), 'get_image'],
        'permission_callback' => '__return_true'
    )
    );
    // register_rest_route('sakura/v1', '/database/update', array(
    //     'methods' => 'GET',
    //     'callback' => 'update_database',
    //     'permission_callback'=>'__return_true'
    // ));
    register_rest_route('sakura/v1', '/qqinfo/json', array(
        'methods' => 'GET',
        'callback' => 'get_qq_info',
        'permission_callback' => '__return_true'
    )
    );
    register_rest_route('sakura/v1', '/qqinfo/avatar', array(
        'methods' => 'GET',
        'callback' => 'get_qq_avatar',
        'permission_callback' => '__return_true'
    )
    );
    register_rest_route('sakura/v1', '/captcha/create', array(
        'methods' => 'GET',
        'callback' => 'create_CAPTCHA',
        'permission_callback' => '__return_true'
    )
    ); 
    // 归档页信息
    register_rest_route('sakura/v1', '/archive_info', array(
        'methods' => 'GET',
        'callback' => function (){
            $time_archive = get_transient('time_archive');
            if (!$time_archive) {
                $time_archive = get_archive_info();
                set_transient('time_archive',$time_archive,30);
            }
            return $time_archive;
        },
        'permission_callback' => '__return_true'
    )
    ); 
});

function sakura_get_rest_request_nonce(WP_REST_Request $request)
{
    $nonce = $request->get_header('X-WP-Nonce');
    if (!$nonce) {
        $nonce = $request->get_param('_wpnonce');
    }

    return $nonce ? sanitize_text_field(wp_unslash($nonce)) : '';
}

function sakura_verify_rest_request_nonce(WP_REST_Request $request)
{
    $nonce = sakura_get_rest_request_nonce($request);
    return $nonce && wp_verify_nonce($nonce, 'wp_rest');
}

/**
 * Image uploader response
 */
function upload_image(WP_REST_Request $request)
{
    // see: https://developer.wordpress.org/rest-api/requests/

    // handle file params $file === $_FILES
    /**
     * curl \
     *   -F "filecomment=This is an img file" \
     *   -F "cmt_img_file=@screenshot.jpg" \
     *   https://dev.2heng.xin/wp-json/sakura/v1/image/upload
     */
    // $file = $request->get_file_params();
    if (!sakura_verify_rest_request_nonce($request)) {
        $output = array(
            'status' => 403,
            'success' => false,
            'message' => 'Unauthorized client.'
        );
        $result = new WP_REST_Response($output, 403);
        $result->set_headers(array('Content-Type' => 'application/json'));
        return $result;
    }
    $images = new \Sakura\API\Images();
    $files = $request->get_file_params();
    // 验证上传文件存在且可读
    if (empty($files['cmt_img_file']['tmp_name']) || !is_readable($files['cmt_img_file']['tmp_name'])) {
        return new WP_REST_Response(array(
            'status' => 400,
            'success' => false,
            'message' => 'Missing or invalid upload file.'
        ), 400);
    }
    switch (iro_opt("img_upload_api")) {
        case 'imgur':
            $image = file_get_contents($files["cmt_img_file"]["tmp_name"]);
            $API_Request = $images->Imgur_API($image);
            break;
        case 'smms':
            $image = $files;
            $API_Request = $images->SMMS_API($image);
            break;
        case 'chevereto':
            $image = file_get_contents($files["cmt_img_file"]["tmp_name"]);
            $API_Request = $images->Chevereto_API($image);
            break;
        case 'lsky':
            $image = $files;
            $API_Request = $images->LSKY_API($image);
            break;
    }

    $result = new WP_REST_Response($API_Request, $API_Request['status']);
    $result->set_headers(array('Content-Type' => 'application/json'));
    return $result;
}

/*
 * update database rest api
 * @rest api接口路径：https://sakura.2heng.xin/wp-json/sakura/v1/database/update
 */
// function update_database() {
//     if (iro_opt('random_graphs_options') == "webp_optimization") {
//         $output = Cache::update_database();
//         $result = new WP_REST_Response($output, 200);
//         return $result;
//     } else {
//         return new WP_REST_Response("Invalid access", 200);
//     }
// }

/*
 * 定制实时搜索 rest api
 * @rest api接口路径：https://sakura.2heng.xin/wp-json/sakura/v1/cache_search/json
 * @可在cache_search_json()函数末尾通过设置 HTTP header 控制 json 缓存时间
 */
function cache_search_json(WP_REST_Request $request)
{
    if (!sakura_verify_rest_request_nonce($request)) {
        $output = array(
            'status' => 403,
            'success' => false,
            'message' => 'Unauthorized client.'
        );
        $result = new WP_REST_Response($output, 403);
    } else {
        $output = Cache::search_json();
        $result = new WP_REST_Response($output, 200);
    }
    $result->set_headers(
        array(
            'Content-Type' => 'application/json',
            'Cache-Control' => 'max-age=3600', // json 缓存控制
        )
    );
    return $result;
}

/**
 * QQ info
 * https://sakura.2heng.xin/wp-json/sakura/v1/qqinfo/json
 */
function get_qq_info(WP_REST_Request $request)
{
    if (!sakura_verify_rest_request_nonce($request)) {
        $output = array(
            'status' => 403,
            'success' => false,
            'message' => 'Unauthorized client.'
        );
    } else {
        $qq = sanitize_text_field($request->get_param('qq'));
        if (empty($qq)) {
            $output = array(
                'status' => 400,
                'success' => false,
                'message' => 'Bad Request'
            );
        } else {
            $output = QQ::get_qq_info($qq);
        }
    }

    $result = new WP_REST_Response($output, $output['status']);
    $result->set_headers(array('Content-Type' => 'application/json'));
    return $result;
}

/**
 * QQ头像链接解密
 * https://sakura.2heng.xin/wp-json/sakura/v1/qqinfo/avatar
 */
function get_qq_avatar(WP_REST_Request $request)
{
    $encrypted = sanitize_text_field($request->get_param('qq'));
    if (empty($encrypted)) {
        return new WP_REST_Response(array('status' => 400, 'message' => 'Missing qq parameter'), 400);
    }
    $imgurl = QQ::get_qq_avatar($encrypted);
    if (!$imgurl) {
        return new WP_REST_Response(array('status' => 404, 'message' => 'Avatar not found'), 404);
    }
    if (iro_opt('qq_avatar_link') == 'type_2') {
        $imgdata = wp_remote_retrieve_body(wp_remote_get(esc_url_raw($imgurl)));
        if (empty($imgdata)) {
            return new WP_REST_Response(array('status' => 500, 'message' => 'Failed to fetch avatar'), 500);
        }
        // 二进制数据需直接输出，避免 REST 框架 JSON 编码导致响应损坏
        header('Content-Type: image/jpeg');
        header('Cache-Control: max-age=86400');
        echo $imgdata;
        exit;
    } else {
        $response = new WP_REST_Response();
        $response->set_status(302);
        $response->header('Location', esc_url_raw($imgurl));
    }
    return $response;
}

function create_CAPTCHA()
{
    $CAPTCHA = new Captcha();
    $response = new WP_REST_Response($CAPTCHA->create_captcha_img());
    $response->set_status(200);
    $response->set_headers(array('Content-Type' => 'application/json'));
    return $response;
}
