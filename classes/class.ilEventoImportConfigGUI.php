<?php

/**
 * Class ilEventoImportConfigGUI
 */
class ilEventoImportConfigGUI extends ilPluginConfigGUI
{
    private const ALLOWED_PATH_SCHEMA_ELEMENTS = ['department', 'kind', 'year'];

    private $settings;
    private $tree;
    private $tpl;
    private $ctrl;
    private $hard_coded_department_mapping;

    public function __construct()
    {
        global $DIC;
        $this->settings = new ilSetting("crevento");
        $this->tree = $DIC->repositoryTree();
        $this->tpl = $DIC->ui()->mainTemplate();
        $this->ctrl = $DIC->ctrl();

        $this->hard_coded_department_mapping = [
            "Hochschule Luzern" => "HSLU",
            "Design & Kunst" => "DK",
            "Informatik" => "I",
            "Musik" => "M",
            "Soziale Arbeit" => "SA",
            "Technik & Architektur" => "TA",
            "Wirtschaft" => "W"
        ];
    }

    public function performCommand($cmd)
    {
        switch ($cmd) {
            case 'configure':
                $content = $this->getFunctionalityBoardAsString();
                $this->tpl->setContent($content);
                break;

            case 'reload_repo_locations':
                $locations = $this->reloadRepositoryLocations();
                $saved_locations_string = $this->locationsToHTMLTable($locations);


                ilUtil::sendSuccess('Repository tree reloaded successfully. Following locations are currently saved:<br>' . $saved_locations_string, true);
                $this->ctrl->redirect($this, 'configure');
                break;

            case 'fetch_data_set_users':
            case 'fetch_data_set_events':
                try {
                    $importer = $this->buildImporter($cmd);
                    $form = $this->initDataSetForm();
                    if ($form->checkInput()) {
                        $skip = (int) $form->getInput('skip');
                        $take = (int) $form->getInput('take');
                        $output = $importer->fetchSpecificDataSet($skip, $take);

                        if (!is_null($output)) {
                            $cmd = htmlspecialchars($cmd);
                            $output = htmlspecialchars(print_r($output, true));
                            ilUtil::sendSuccess("CMD = $cmd, Skip = $skip, Take = $take <br><br>Output from request:<br> $output", true);
                        } else {
                            $cmd = htmlspecialchars($cmd);
                            ilUtil::sendFailure("Got no answer for CMD = $cmd, Skip = $skip, Take = $take", true);
                        }
                    } else {
                        throw new InvalidArgumentException('Error in form input');
                    }
                } catch (Exception $e) {
                    ilUtil::sendFailure('Exception: ' . print_r([$e->getMessage(), $e->getTraceAsString()], true));
                }

                $this->ctrl->redirect($this, 'configure');
                break;

            case 'fetch_record_user':
            case 'fetch_record_event':
            case 'fetch_user_photo':
                try {
                    $importer = $this->buildImporter($cmd);
                    $form = $this->initDataRecordForm();
                    if ($form->checkInput()) {
                        $id_from_form = (int) $form->getInput('record_id');
                        $output = $importer->fetchDataRecordById($id_from_form);

                        if (!is_null($output)) {
                            $cmd = htmlspecialchars($cmd);
                            $output = htmlspecialchars(print_r($output, true));
                            ilUtil::sendSuccess("CMD = $cmd, ID = $id_from_form <br><br>Output from request:<br> $output", true);
                        } else {
                            $cmd = htmlspecialchars($cmd);
                            ilUtil::sendFailure("Got no answer for CMD = $cmd and ID = $id_from_form", true);
                        }
                    } else {
                        throw new InvalidArgumentException('Error in form input');
                    }
                } catch (Exception $e) {
                    ilUtil::sendFailure('Exception: ' . print_r([$e->getMessage(), $e->getTraceAsString()], true));
                }
                $this->ctrl->redirect($this, 'configure');
                break;

            default:
                ilUtil::sendFailure('Command not found', true);
                $this->ctrl->redirect($this, 'configure');
                break;
        }
    }


    private function getFunctionalityBoardAsString()
    {
        global $DIC;
        $ui_factory = $DIC->ui()->factory();
        $field_factory = $ui_factory->input()->field();

        // Reload tree
        $ui_components = [];
        $link = $this->ctrl->getLinkTarget($this, 'reload_repo_locations');
        $ui_components[] = $ui_factory->button()->standard("Reload Repository Locations", $link);

        // Fetch data set form
        $form = $this->initDataSetForm();

        $form_html = $form->getHTML();

        // Fetch data record form
        $form = $this->initDataRecordForm();

        $form_html .= $form->getHTML();

        return $DIC->ui()->renderer()->render($ui_components) . $form_html;
    }

    private function buildImporter($cmd)
    {
        global $DIC;
        $api_importer_settings = new \EventoImport\communication\ApiImporterSettings(new ilSetting('crevento'));
        $iterator = new ilEventoImporterIterator($api_importer_settings->getPageSize());
        $logger = new ilEventoImportLogger($DIC->database());
        $request_client = $this->buildRequestService($api_importer_settings);

        switch ($cmd) {
            case 'fetch_record_user':
            case 'fetch_data_set_users':
                return new \EventoImport\communication\EventoUserImporter(
                    $iterator,
                    $api_importer_settings,
                    $logger,
                    $request_client
                );
                break;

            case 'fetch_record_event':
            case 'fetch_data_set_events':
                return new \EventoImport\communication\EventoEventImporter(
                    $iterator,
                    $api_importer_settings,
                    $logger,
                    $request_client
                );
                break;

            case 'fetch_user_photo':
                return new \EventoImport\communication\EventoUserPhotoImporter($request_client);
        }
    }

    private function initDataRecordForm() : ilPropertyFormGUI
    {
        $form = new ilPropertyFormGUI();
        $form->setTitle('Fetch Data Record');
        $form->setFormAction($this->ctrl->getFormAction($this));
        $form->addCommandButton('fetch_record_user', 'Fetch User');
        $form->addCommandButton('fetch_record_event', 'Fetch Event');

        $take = new ilNumberInputGUI('Id', 'record_id');
        $form->addItem($take);

        return $form;
    }

    private function initDataSetForm() : ilPropertyFormGUI
    {
        $form = new ilPropertyFormGUI();
        $form->setTitle('Fetch Data Set');
        $form->setFormAction($this->ctrl->getFormAction($this, 'fetch_data_set'));
        $form->addCommandButton('fetch_data_set_users', 'Fetch Users');
        $form->addCommandButton('fetch_data_set_events', 'Fetch Events');

        $take = new ilNumberInputGUI('Take', 'take');
        $form->addItem($take);

        $skip = new ilNumberInputGUI('Skip', 'skip');
        $form->addItem($skip);

        return $form;
    }

    public function buildRequestService(\EventoImport\communication\ApiImporterSettings $importer_settings) : \EventoImport\communication\request_services\RequestClientService
    {
        //return new \EventoImport\communication\request_services\FakeRestClientService();
        return new \EventoImport\communication\request_services\RestClientService(
            $importer_settings->getUrl(),
            $importer_settings->getTimeoutAfterRequest(),
            $importer_settings->getTimeoutFailedRequest()
        );
    }

    private function locationsToHTMLTable(array $locations) : string
    {
        $saved_locations_string = "<table style='width: 100%'>";
        if (count($locations) > 0) {
            $saved_locations_string .= "<tr>";
            foreach ($locations[0] as $key => $value) {
                $saved_locations_string .= "<th><b>$key</b></th>";
            }
            $saved_locations_string .= "<tr>";
        }
        foreach ($locations as $location) {
            $saved_locations_string .= "<tr>";
            foreach ($location as $key => $value) {
                $saved_locations_string .= "<td>$value</td>";
            }
            $saved_locations_string .= '</tr>';
        }

        $saved_locations_string .= "</table>";
        return $saved_locations_string;
    }

    private function fetchRefIdForObjTitle(int $root_ref_id, string $searched_obj_title) : ?int
    {
        foreach ($this->tree->getChildsByType($root_ref_id, 'cat') as $child_node) {
            $child_ref = $child_node['child'];
            $obj_id = ilObject::_lookupObjectId($child_ref);
            if (ilObject::_lookupTitle($obj_id) == $searched_obj_title) {
                return $child_ref;
            }
        }

        return null;
    }

    private function getMappedDepartmentName(string $ilias_department_cat) : string
    {
        if (isset($this->hard_coded_department_mapping[$ilias_department_cat])) {
            return $this->hard_coded_department_mapping[$ilias_department_cat];
        } else {
            return $ilias_department_cat;
        }
    }

    private function reloadRepositoryLocations()
    {
        global $DIC;

        $json_settings = $this->settings->get('crevento_location_settings');
        $locations_settings = json_decode($json_settings, true);

        $location_repository = new \EventoImport\import\db\repository\EventLocationsRepository($DIC->database());
        $location_repository->purgeLocationTable();
        $repository_root_ref_id = 1;
        foreach ($locations_settings['departments'] as $department) {
            $department_ref_id = $this->fetchRefIdForObjTitle($repository_root_ref_id, $department);
            if ($department_ref_id) {
                foreach ($locations_settings['kinds'] as $kind) {
                    $kind_ref_id = $this->fetchRefIdForObjTitle($department_ref_id, $kind);
                    if ($kind_ref_id) {
                        foreach ($locations_settings['years'] as $year) {
                            $destination_ref_id = $this->fetchRefIdForObjTitle($kind_ref_id, $year);
                            if ($destination_ref_id) {
                                $location_repository->addNewLocation($this->getMappedDepartmentName($department), $kind, $year, $destination_ref_id);
                            }
                        }
                    }
                }
            }
        }

        return $location_repository->fetchAllLocations();
    }
}
