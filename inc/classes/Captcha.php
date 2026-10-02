<?php

declare(strict_types=1);
//TODO: 打个标记 下次一定改静态类（
namespace Sakura\API;

class Captcha
{
    private $captchaText;
    private $captchaResult;

    /**
     * CAPTCHA constructor.
     */
    public function __construct()
    {
        $this->captchaText = '';
        $this->captchaResult = '';
    }

    /**
     * create_captcha
     *
     * @return void
     */
    private function create_captcha(): void
    {
        $n1 = mt_rand(10, 99);
        $n2 = mt_rand(10, 99);
        if (mt_rand(0, 1)) {
            //加法
            $this->captchaText = "{$n1}+{$n2}=?";
            $this->captchaResult = $n1 + $n2;
        } else {
            //减法(避免负数)
            $min = min($n1, $n2);
            $max = max($n1, $n2);
            $this->captchaText = "{$max}-{$min}=?";
            $this->captchaResult = $max - $min;
        }
    }

    /** Read the existing form contract without accepting arrays or oversized timestamps. */
    public static function check_request(string $answer_field): array
    {
        $answer = $_POST[$answer_field] ?? '';
        $timestamp = $_POST['timestamp'] ?? '';
        $id = $_POST['id'] ?? '';
        if (!is_string($answer) || !is_string($timestamp) || !is_string($id)
            || !preg_match('/\A[0-9]{1,10}\z/', $timestamp)) {
            return ['code' => 3, 'data' => '', 'msg' => __('Bad Request.', 'sakurairo')];
        }
        return (new self())->check_captcha(trim($answer), (int) $timestamp, $id);
    }

    /**
     * create_captcha_img
     *
     * @return array
     */
    public function create_captcha_img(): array
    {
        //动态计算验证码难度
        $level = iro_opt('iro_captcha_level') / 100;
        $conf = array(
          'noise' => (int)(700 + 500 * $level),
          'curves' => (int)(8 + 6 * $level),
          'quality' => (int)(100 - 40 * $level)
        );
        
        //创建验证码
        $this->create_captcha();
        // 自 wordpress 6.4.0 起，弃用了STYLESHEETPATH定义. 为确保子主题能正常使用，应当使用get_template_directory()接口
        $font = get_template_directory() . '/inc/KumoFont.ttf';
        
        //创建画布
        $image = imagecreatetruecolor(210, 60);
        //填充背景色
        $color = imagecolorallocate($image, mt_rand(200, 255), mt_rand(200, 255), mt_rand(200, 255));
        imagefill($image, 0, 0, $color);

        //绘制文字
        $chars = str_split($this->captchaText);
        for ($i = 0; $i < count($chars); $i++) {
            $char = $chars[$i];
            $color = imagecolorallocate($image, mt_rand(0, 150), mt_rand(0, 150), mt_rand(0, 150));
            $x = 30 * $i + 10;
            $y = 30 + mt_rand(-5, 5);
            //加减符号不倾斜 并加大字体
            $size = ($i === 2) ? 30 : 20;
            $angle = ($i === 2) ? 0 : mt_rand(-25, 25);
            imagettftext($image, $size, $angle, $x, $y, $color, $font, $char);
        }
        
        //添加噪点
        for ($i = 0; $i < $conf['noise']; $i++) {
            $color = imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
            imagesetpixel($image, mt_rand(0, 210), mt_rand(0, 60), $color);
        }
        
        // 添加贝塞尔曲线
        for ($i = 0; $i < $conf['curves']; $i++) {
            $color = imagecolorallocate($image, mt_rand(50,150), mt_rand(50, 150), mt_rand(50, 150));
            
            // 贝塞尔曲线控制点
            $x1 = mt_rand(0, 210);
            $x2 = mt_rand(0, 210);
            $cx1 = mt_rand(0, 210);
            $cx2 = mt_rand(0, 210);
            $y1 = mt_rand(0, 60);
            $y2 = mt_rand(0, 60);
            $cy1 = mt_rand(0, 60);
            $cy2 = mt_rand(0, 60);
            
            // 绘制贝塞尔曲线
            for ($t = 0; $t <= 1; $t += 0.01) {
                $xt = (int)((1 - $t) * (1 - $t) * (1 - $t) * $x1 + 3 * (1 - $t) * (1 - $t) * $t * $cx1 + 3 * (1 - $t) * $t * $t * $cx2 + $t * $t * $t * $x2);
                $yt = (int)((1 - $t) * (1 - $t) * (1 - $t) * $y1 + 3 * (1 - $t) * (1 - $t) * $t * $cy1 + 3 * (1 - $t) * $t * $t * $cy2 + $t * $t * $t * $y2);
                imagesetpixel($image, $xt, $yt, $color);
            }
        }
        //启用高斯模糊，进一步降低清晰度
        imagefilter($image, IMG_FILTER_GAUSSIAN_BLUR);
        
        $timestamp = time();
        $id = bin2hex(random_bytes(32));
        $challenge = [
            'time' => $timestamp,
            'answer' => hash_hmac('sha256', $this->captchaResult . ':' . $timestamp . ':' . $id, wp_salt('nonce')),
        ];
        if (!set_transient('cherry_captcha_' . $id, $challenge, 60)) {
            throw new \RuntimeException('Unable to store captcha challenge.');
        }
        //打开缓存区
        ob_start();
        //降低图片质量
        imagejpeg($image, null, $conf['quality']);
        //输出图片
        $captchaimg =  ob_get_contents();
        //销毁缓存区
        ob_end_clean();
        //销毁图片(释放资源)
        imagedestroy($image);
        // 以json格式输出
        $captchaimg = 'data:image/jpeg;base64,' . base64_encode($captchaimg);
        return [
            'code' => 0,
            'data' => $captchaimg,
            'msg' => '',
            'id' => $id,
            'time' => $timestamp,
        ];
    }


    /**
     * check_captcha
     *
     * @param  string $captcha
     * @return array
     */
    public function check_captcha(string $captcha, int $timestamp, string $id): array
    {
        $code = 3;
        $msg = __('Bad Request.', 'sakurairo');
        if (!preg_match('/\A[a-f0-9]{64}\z/', $id)) {
            return ['code' => $code, 'data' => '', 'msg' => $msg];
        }
        $key = 'cherry_captcha_' . $id;
        $challenge = get_transient($key);
        // Consume on every attempt, so a challenge cannot be brute-forced or replayed.
        $consumed = delete_transient($key);
        if (!$consumed || !is_array($challenge) || !isset($challenge['time'], $challenge['answer'])
            || $timestamp !== $challenge['time'] || $timestamp < time() - 60 || $timestamp > time()) {
            $code = 2;
            $msg = __('Captcha timeout.', 'sakurairo');
        } elseif (preg_match('/\A(?:[0-9]|[1-9][0-9]|1[0-8][0-9]|19[0-8])\z/', $captcha)
            && hash_equals($challenge['answer'], hash_hmac('sha256', $captcha . ':' . $timestamp . ':' . $id, wp_salt('nonce')))) {
            $code = 5;
            $msg = __('Captcha check passed.', 'sakurairo');
        } else {
            $code = 1;
            $msg = __('Captcha incorrect.', 'sakurairo');
        }
        return [
            'code' => $code,
            'data' => '',
            'msg' => $msg
        ];
    }
}
