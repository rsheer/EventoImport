<?php declare(strict_types = 1);

namespace EventoImport\import\action\user;

use EventoImport\import\service\IliasUserServices;
use EventoImport\import\db\IliasEventoUserRepository;
use EventoImport\import\Logger;

class ConvertUserToLocalAuth implements UserDeleteAction
{
    private \ilObjUser $ilias_user;
    private int $evento_id;
    private string $converted_auth_mode;
    private IliasEventoUserRepository $user_repo;
    private Logger $logger;
    private int $log_info_code;
    private string $auth_mode;

    public function __construct(\ilObjUser $ilias_user, int $evento_id, string $converted_auth_mode, IliasEventoUserRepository $user_repo, Logger $logger)
    {
        $this->ilias_user = $ilias_user;
        $this->evento_id = $evento_id;
        $this->converted_auth_mode = $converted_auth_mode;
        $this->user_repo = $user_repo;
        $this->logger = $logger;
        $this->log_info_code = Logger::CREVENTO_USR_CONVERTED;
        $this->auth_mode = 'local';
    }

    public function executeAction() : void
    {
        $this->ilias_user->setAuthMode('local');
        $this->ilias_user->update();

        $this->user_repo->deleteEventoIliasUserConnectionByEventoId($this->evento_id);

        $this->logger->logUserImport(
            $this->log_info_code,
            $this->evento_id,
            $this->ilias_user->getLogin(),
            [
                'ilias_user_id' => $this->ilias_user->getId(),
                'new_auth_mode' => $this->auth_mode,
                'deactivate_after_convert' => false,
            ]
        );
    }
}
