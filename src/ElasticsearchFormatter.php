<?php

namespace Experteam\ApiLaravelBase;

use Monolog\Formatter\LineFormatter;

class ElasticsearchFormatter
{
    /**
     * Customize the given logger instance.
     *
     * @param  \Illuminate\Log\Logger  $logger
     * @return void
     */
    public function __invoke($logger)
    {
        foreach ($logger->getHandlers() as $handler) {
            $handler->setFormatter(new LineFormatter(
                "[%datetime%] app.%level_name%: %message% %context% %extra%\n"
            ));
        }
    }

    /**
     * @param  $user
     * @param  $channel
     * @param  $data
     * @return array
     */
    public static function getContext($user,$channel,$data =[]){
        $context = array(
            'id' =>  uniqid(),
            'user' => array(
                'id'=>$user->id,
                'username'=>$user->username
            ),
            'channel' => $channel,
            'timestamp' => date('Y-m-d\TH:i:s'),
        );

        if(!empty($data))
            $context =  array_merge($context,$data);

        return $context;
    }
}
