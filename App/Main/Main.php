<?php

/**
 * Created by PhpStorm.
 * User: william
 * Mail：tzh.wu.qq.com
 */

namespace App\Main;


use App\DB\DB;
use App\Exception\LoginErrException;
use App\Exception\LowLevelException;
use GuzzleHttp\Client;
use Sunra\PhpSimple\HtmlDomParser;
use Swift_Mailer;
use Swift_Message;
use Swift_SmtpTransport;
use Yosymfony\Toml\Toml;
use Yosymfony\Toml\TomlBuilder;

class Main
{
    const TYPE_NOR = 0;
    const TYPE_LOGIN_ERR = 1;
    const TYPE_LLVL_ERR = 2;
    /**
     * 行情事件
     */
    const TYPE_QUOTE_EVENT = 3;

    public $configFile;
    public $config;

    public $income;

    public $status = [];
    public $revenge = [];
    public $coinPrice = [];

    public $client1;
    public $client2;

    public function __construct()
    {
        $this->configFile = __DIR__ . '/../Config/config.toml';
        $this->config = Toml::parse($this->configFile);

        $this->client1 = new Client(
            [
                'base_uri' => $this->config['url']['baseurl'],
                'timeout' => $this->config['requestimeout'],
                'verify' => false,
            ]
        );
        $this->client2 = new Client(
            [
                'timeout' => $this->config['requestimeout'],
                'verify' => false,
            ]
        );
    }

    public function __destruct()
    {
        unset($this->client1);
        unset($this->client2);

    }

    public function test()
    {
        $phones = array();
        foreach ($this->config['phone'] as $phone) {
            $phones[] = $phone['phone'];
        }
        var_dump($phones);
        $results = DB::getDB()->select('', 'phone', array(
            'have' => 1
        ));
        foreach ($results as $result) {
            if (!in_array($result, $phones)) {
                var_dump($result);
            }
        }
        var_dump(array_diff($phones, $results));
        exit;
    }


    public function main()
    {
        foreach ($this->config['phone'] as &$item) {
            $tp = array();

            if (empty($item['token'])) {
                $tp['token'] = $item['token'] = $token = $this->login($item['phone'], $item['password']);
            }

            usleep($this->config['requestgap']);

            $codes = $this->getMinerStatus($item['phone'], $item['token']);

            usleep($this->config['requestgap']);

            $tp['codes'] = $codes;
            $this->income[$item['phone']]['codes'] = $codes;
            //mark
            $this->income[$item['phone']]['mark'] = $item['mark'];

            //获取余额、提现中
            $this->getPocket($item['phone'], $item['token']);

            list($tp['income'], $tp['incomeyes']) = $this->getIncome($item['phone'], $item['token']);
            $this->income[$item['phone']]['incomeyes'] = $tp['incomeyes'];
            $this->income[$item['phone']]['totalincom'] = $tp['income'];
            $this->income[$item['phone']]['mark'] = $item['mark'];

            $tp['balance'] = $this->income[$item['phone']]['balance'];
            $tp['withdraw'] = $this->income[$item['phone']]['withdraw'];

            $this->status[$item['phone']] = $tp;
        }


        $this->revenge = $this->handler();
        $this->coinPrice = $this->getCoinPrice();

        $this->mail($this->messageBuilder(self::TYPE_NOR));

//        file_put_contents('a.log', \GuzzleHttp\json_encode($this->config));


    }

    function messageBuilder($type = self::TYPE_NOR, ...$args)
    {
        $message = '';
        switch ($type) {
            case self::TYPE_NOR:
                $result[] = $this->revenge[0];
                $result[] = $this->revenge[1];
                $result[] = $this->revenge[2];
                $result[] = $this->revenge[3];
                $result[] = $this->revenge[4];
                $result[] = $this->revenge[5];
                $result[] = $this->revenge[6];
                $result[] = $this->revenge[7];
                $result[] = $this->revenge[8];
                $machinePlain = '';
                $start = 0;
                foreach ($this->income as $key => $income) {
                    $machinePlain .= sprintf($this->config['mail']['tp5'], ...[
                        $start++,
                        $key,
                        is_array($income['codes']) ? implode(',', array_map(function ($value) {
                            return $value['code'] . '|' . $value['onlineStatus'];
                        }, $income['codes'])) : implode('|', array_shift($income['codes'])),
                        floatval($income['balance']),
                        floatval($income['withdraw']),
                        $income['totalincom'],
                        $income['incomeyes'],
                        $income['mark'],
                    ]);
                }
                $result[] = $machinePlain;
                $result[] = $this->coinPrice[0];
                $result[] = $this->coinPrice[1];
                $result[] = $this->coinPrice[2];
                $result[] = $this->coinPrice[1] * $this->revenge[5];
                $result[] = $this->coinPrice[1] * $this->revenge[7];
                $message = sprintf($this->config['mail']['tp1'], ...$result);
                break;
            case self::TYPE_LOGIN_ERR:
                $message = sprintf($this->config['mail']['tp2'], ...$args);
                break;
            case self::TYPE_LLVL_ERR:
                $message = sprintf($this->config['mail']['tp3'], ...$args);
                break;
            case self::TYPE_QUOTE_EVENT:
                $message = sprintf($this->config['mail']['tp4'], ...$args);
                break;
        }

        unset($result);
        unset($args);
        unset($type);

        return $message;

    }

    public function buildSubMessage(...$args)
    {
        return sprintf($this->config['mail']['tp5'], $args);
    }

    function mail($mailContent)
    {
        $transport = (new Swift_SmtpTransport('smtp.163.com', 465, 'ssl'))
            ->setUsername($this->config['mail']['loginusername'])
            ->setPassword($this->config['mail']['loginpwd']);

        $mailer = new Swift_Mailer($transport);

        $message = (new Swift_Message($this->config['mail']['theme'] . '-' . date('Y-m-d H:i:s')))
            ->setFrom([$this->config['mail']['loginusername'] => $this->config['mail']['fromnickname']])
            ->setTo([$this->config['mail']['toaddress']], $this->config['mail']['tousername'])
            ->setBody($mailContent, $this->config['mail']['mailtype']);

        $result = $mailer->send($message);
        unset($mailer);
        unset($transport);
        unset($message);

        return $result;
    }


    function handler()
    {
        $totalMiner = 0;
        $totalMinerOffline = 0;
        $totalMinerOnline = 0;
        $totalIncome = 0;
        $totalIncomeYes = 0;
        $totalAccount = 0;
        $totalBalance = 0;
        $totalWithdraw = 0;
        foreach ($this->status as $state) {
            $totalAccount++;
            foreach ($state['codes'] as $code) {
                $totalMiner++;
                if ($code['onlineStatus'] == 0) {
                    $totalMinerOnline++;
                } else {
                    $totalMinerOffline++;
                }
            }

            $totalIncome += $state['income'];

            $totalIncomeYes += $state['incomeyes'];

            $totalBalance += $state['balance'];

            $totalWithdraw += $state['withdraw'];
        }

        return [
            date('Y-m-d H:i:s'),
            $totalAccount,
            $totalMiner,
            $totalMinerOnline,
            $totalMinerOffline,
            $totalIncome,
            $totalIncomeYes,
            $totalBalance,
            $totalWithdraw
        ];
    }

    function getIncome($phone, $token)
    {

        $response = $this->client1->post($this->config['url']['income'], [
            'headers' => $this->getHeader(),
            'form_params' => [
//                'phoneNumber' => $phone,
                'uid' => $token,
            ]
        ]);

        $content = $response->getBody()->getContents();
        unset($response);

        $content = json_decode($content, true);

        if (!$content['Err']) {
            //store history
//            $this->income[$phone]['history'] = $content['data']['history'];
//            if (!empty($content['data']['history'])) {
//                $last = array_pop($content['data']['history']);
//            }
            if (!empty($content['data']['YesterdayIncome'])) {
                $last = $content['data']['YesterdayIncome'];
            }
            return array(
                $content['data']['Balance'],
                isset($last) ? $last : 0,
            );
        }

        throw new LowLevelException('获取收入信息失败' . json_encode($content));
    }

    public function getPocket($phone, $token)
    {
        $response = $this->client1->post($this->config['url']['pocket'], [
            'headers' => $this->getHeader(),
            'form_params' => [
//                'phoneNumber' => $phone,
                'uid' => $token,
            ]
        ]);

        $content = $response->getBody()->getContents();
        unset($response);

        $content = json_decode($content, true);

        if (!$content['Err']) {
            //store history
            $this->income[$phone]['balance'] = $content['data']['Balance'];
            $this->income[$phone]['withdraw'] = $content['data']['ProcessingAmount'];
            return;
        }

        throw new LowLevelException('获取钱包信息失败' . json_encode($content));
    }

    function login($phone, $pwd)
    {
        $result = $this->client1->post($this->config['url']['login'], [
            'headers' => $this->getHeader(),
            'form_params' => [
                'phoneNumber' => $phone,
                'password' => md5($pwd),
                'areaCode' => $this->config['areacode'],
            ]
        ]);
        $content = $result->getBody()->getContents();

        $content = json_decode($content, true);

        unset($result);
        if (!$content['Err']) {
            return $content['data']['UID'];
        } else {
            throw new LoginErrException(json_encode($content));
        }
    }

    function getMinerStatus($phone, $token)
    {

        $response = $this->client1->post($this->config['url']['getminercode'], [
            'headers' => $this->getHeader(),
            'form_params' => [
                'phoneNumber' => $phone,
//                'token' => $token,
                'areaCode' => 86,
            ]
        ]);

        $content = $response->getBody()->getContents();
        unset($response);

        $content = json_decode($content, true);

        if (empty($content['Err'])) {
            return $content['data']['CodeList'];
        }
        throw new LowLevelException('获取矿机状态失败' . json_encode($content));

    }

    function defaultHeaderBuilder()
    {
        $tp = <<<TP
Content-Type: application/x-www-form-urlencoded
Accept-Encoding: gzip
Accept-Language:zh-CN,zh;q=0.8
User-Agent: okhttp/3.8.0
TP;
        $tps = explode("\n", $tp);
        $headers = [];
        foreach ($tps as $tp) {
            list($key, $value) = explode(':', $tp, 2);
            $key = trim($key);
            $value = trim($value);
            $headers[$key] = $value;
        }

        return $headers;
    }

    function getHeader()
    {
        $headers = $this->defaultHeaderBuilder();
        return $headers;
    }

    function getCoinPrice()
    {
        $response = $this->client2->get($this->config['price']['url'], [
            'headers' => $this->getHeader(),
        ]);
        $content = $response->getBody()->getContents();

        $dom = HtmlDomParser::str_get_html($content);
        $dom1 = $dom->find('#top_last_rate_change', 0);
        unset($dom);
        if (empty($dom1)) {
            throw new LowLevelException();
        }
        $pricedom = $dom1->find('.price-dl', 0);
        $ratedom = $dom1->children(1);

        $rate = $ratedom->children(0)->innertext();
        $rate = strip_tags($rate);

        $dollars = $pricedom->find('#currPrice', 0)->innertext();
        $ync = $pricedom->find('#currFiat', 0)->innertext();

        $pricedom->clear();
        $ratedom->clear();
        $dom1->clear();
        unset($pricedom);
        unset($ratedom);
        unset($dom1);
        unset($response);

        return [
            $dollars,
            $ync,
            $rate,
        ];
    }

}
