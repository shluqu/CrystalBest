<?php

namespace app\command;

use app\service\C2c\C2cService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

final class C2cExpire extends Command
{
    protected function configure()
    {
        $this->setName('c2c:expire')->setDescription('Expire unpaid C2C orders and refund escrow');
    }

    protected function execute(Input $input, Output $output)
    {
        $orders = (new C2cService(null))->expireDueOrders(500);
        $output->writeln('expired=' . count($orders));
        foreach ($orders as $orderNo) {
            $output->writeln($orderNo);
        }
        return 0;
    }
}
