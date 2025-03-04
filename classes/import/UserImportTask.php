<?php declare(strict_types=1);

namespace EventoImport\import;

use EventoImport\communication\EventoUserImporter;
use EventoImport\import\data_management\ilias_core_service\IliasUserServices;
use EventoImport\import\action\UserImportActionDecider;
use EventoImport\import\data_management\repository\IliasEventoUserRepository;
use EventoImport\communication\api_models\EventoUser;

/**
 * Copyright (c) 2017 Hochschule Luzern
 * This file is part of the EventoImport-Plugin for ILIAS.
 * EventoImport-Plugin for ILIAS is free software: you can redistribute
 * it and/or modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 * EventoImport-Plugin for ILIAS is distributed in the hope that
 * it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with EventoImport-Plugin for ILIAS.  If not,
 * see <http://www.gnu.org/licenses/>.
 */
class UserImportTask
{
    private EventoUserImporter $evento_importer;
    private IliasUserServices $ilias_user_service;
    private IliasEventoUserRepository $evento_user_repo;
    private UserImportActionDecider $user_import_action_decider;
    private Logger $evento_logger;

    public function __construct(
        EventoUserImporter $importer,
        UserImportActionDecider $user_import_action_decider,
        IliasUserServices $ilias_user_service,
        IliasEventoUserRepository $evento_user_repo,
        Logger $logger
    ) {
        $this->evento_importer = $importer;
        $this->user_import_action_decider = $user_import_action_decider;
        $this->ilias_user_service = $ilias_user_service;
        $this->evento_user_repo = $evento_user_repo;
        $this->evento_logger = $logger;
    }

    public function run() : void
    {
        $this->importUsers();
        $this->convertDeletedAccounts();
        $this->setUserTimeLimits();
    }

    private function importUsers() : void
    {
        do {
            try {
                $this->importNextUserPage();
            } catch (\ilEventoImportCommunicationException $e) {
                throw $e;
            } catch (\Exception $e) {
                $this->evento_logger->logException('User Import', $e->getMessage());
            }
        } while ($this->evento_importer->hasMoreData());
    }

    private function importNextUserPage() : void
    {
        foreach ($this->evento_importer->fetchNextUserDataSet() as $data_set) {
            try {
                $evento_user = new EventoUser($data_set);

                if ($evento_user->isLockdownAccount()) {
                    $this->handleDeliveredLockdownAccount($evento_user);
                } else {
                    $action = $this->user_import_action_decider->determineImportAction($evento_user);
                    $action->executeAction();
                }
            } catch (\ilEventoImportApiDataException $e) {
                $data = $e->getApiData();
                if (isset($data[EventoUser::JSON_ID])) {
                    $id = $data[EventoUser::JSON_ID];
                    $evento_id_msg = "Evento ID: $id";
                } else {
                    $evento_id_msg = "Evento ID not given";
                }
                $this->evento_logger->logException('API Data Exception - Importing Event', $evento_id_msg . ' - ' . $e->getMessage());
            } catch (\Exception $e) {
                $this->evento_logger->logException('User Import', $e->getMessage());
            }
        }
    }

    private function handleDeliveredLockdownAccount(EventoUser $evento_user)
    {
        $ilias_user_id = $this->evento_user_repo->getIliasUserIdByEventoId($evento_user->getEventoId());
        $this->evento_user_repo->deleteEventoIliasUserConnectionByEventoId($evento_user->getEventoId());

        if (!is_null($ilias_user_id)) {
            $ilias_user_obj = $this->ilias_user_service->getExistingIliasUserObjectById($ilias_user_id);
            $this->ilias_user_service->deactivateUserAccount($ilias_user_obj);
        }
        $this->evento_logger->logUserImport(
            Logger::CREVENTO_USR_LOCKDOWN,
            $evento_user->getEventoId(),
            $evento_user->getLoginName(),
            ['api_data' => $evento_user->getDecodedApiData()]
        );
    }

    /**
     * User accounts which are deleted by evento should either be converted to a local account (students) or deactivate (staff)
     * Since there is no "getDeletedAccounts"-Method anymore, this Plugin has to find those "not anymore imported"-users
     * by itself. For this reason, every imported account has a last-imported-timestamp. With this value, users which have not
     * been imported since a longer time can be found.
     */
    private function convertDeletedAccounts()
    {
        $list = $this->evento_user_repo->getUsersWithLastImportOlderThanOneWeek(IliasEventoUserRepository::TYPE_HSLU_AD);

        foreach ($list as $evento_id => $ilias_user_id) {
            try {
                // Ensure that the user is not being returned by the api right now
                $result = $this->evento_importer->fetchUserDataRecordById($evento_id);

                if (is_null($result)) {
                    $action = $this->user_import_action_decider->determineDeleteAction($ilias_user_id, $evento_id);
                    $action->executeAction();
                } else {
                    $this->evento_user_repo->registerUserAsDelivered($result->getEventoId());
                    $this->evento_logger->logException('Deleting User', 'User which was not delivered during "Import Users" can be requested by ID. Therefore it still exsits. Evento ID = ' . $evento_id);
                }
            } catch (\Exception $e) {
                $this->evento_logger->logException('Convert Deleted User Accounts', "Exception on deleting user with evento_id $ilias_user_id"
                    . ', exception message: ' . $e->getMessage());
            }
        }
    }

    /**
     * User accounts which don't have a time limitation are limited to
     * two years since their creation.
     */
    private function setUserTimeLimits()
    {
        $this->ilias_user_service->setUserTimeLimits();
    }
}
