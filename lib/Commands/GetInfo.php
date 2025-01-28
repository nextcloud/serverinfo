<?php

namespace OCA\ServerInfo\Commands;

use OC\Core\Command\Base;
use OCA\ServerInfo\Service\InfoService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GetInfo extends Base {
    private InfoService $serverInfoService;

    public function __construct(InfoService $serverInfoService) {
        parent::__construct();
        $this->serverInfoService = $serverInfoService;
    }

    protected function configure(): void {
        parent::configure();
        $this->setName('serverinfo:info')
             ->setDescription('Displays server information');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $serverInfo = $this->serverInfoService->getServerInfo();
        $this->writeArrayInOutputFormat($input, $output, $serverInfo);
        
        return 0;
    }
}
