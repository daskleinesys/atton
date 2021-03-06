<?php
namespace Attack\View\Content\Operations;

use Attack\Controller\Game\Moves\SelectStartController;
use Attack\Exceptions\ControllerException;
use Attack\Model\Game\ModelGameArea;
use Attack\Model\Game\Moves\ModelSelectStartMove;
use Attack\Model\Areas\ModelArea;
use Attack\Model\Game\Start\ModelOptionType;
use Attack\Model\Game\Start\ModelStartRegion;
use Attack\Model\Game\ModelGame;
use Attack\Model\User\ModelIsInGameInfo;
use Attack\Model\User\ModelUser;

class ContentSelectStart extends Interfaces\ContentOperation {

    private $id_set;
    private $possibleStartRegions;

    public function getTemplate() {
        return 'selectstart';
    }

    public function run(array &$data) {
        $data['template'] = $this->getTemplate();
        $this->addCurrentGameInfo($data);

        // get Model Data
        /** @var $iig ModelIsInGameInfo */
        $iig = ModelIsInGameInfo::getIsInGameInfo(ModelUser::getCurrentUser()->getId(), ModelGame::getCurrentGame()->getId());
        $this->id_set = $iig->getIdStartingSet();
        $this->possibleStartRegions = ModelStartRegion::getRegionsForSet($this->id_set); // array(int id_opttype => array(int option_number => array(int id_area => ModelStartRegion)))

        // update moves
        if (isset($_POST['selectstart'])) {
            $this->selectOption($data);
        }
        if (isset($_POST['fixate_start'])) {
            $this->fixateMove($data);
        }

        // parse moves
        $this->checkFixate($data, PHASE_SELECTSTART);
        $this->checkCurrentPhase($data, PHASE_SELECTSTART);
        $this->parseOptions($data);
    }

    private function selectOption(array &$data) {
        $moveController = new SelectStartController(ModelUser::getCurrentUser()->getId(), ModelGame::getCurrentGame()->getId());

        foreach ($this->possibleStartRegions as $option_types) {
            foreach ($option_types as $option_number => $options) {
                if (isset($_POST['countries_' . $option_number])) {
                    try {
                        $moveController->selectStartAreas($this->id_set, $option_number, $_POST['countries_' . $option_number]);
                    } catch (ControllerException $ex) {
                        $data['errors'] = array(
                            'message' => $ex->getMessage()
                        );
                        return;
                    }
                }
            }
        }
    }

    private function fixateMove(array &$data) {
        $moveController = new SelectStartController(ModelUser::getCurrentUser()->getId(), ModelGame::getCurrentGame()->getId());

        try {
            $moveController->finishMove();
        } catch (ControllerException $ex) {
            $data['errors'] = array(
                'message' => $ex->getMessage()
            );
        }
    }

    private function parseOptions(array &$data) {
        $viewData = array();

        foreach ($this->possibleStartRegions as $id_option_type => $option_types) {
            $optionType = ModelOptionType::getOptionType($id_option_type);

            foreach ($option_types as $option_number => $options) {
                $optionViewData = array();
                $optionViewData['number'] = $option_number;
                $optionViewData['countrySelectUnitCount'] = $optionType->getUnits();
                $optionViewData['countrySelectCount'] = $optionType->getCountries();
                $optionViewData['areas'] = array();

                foreach ($options as $id_area => $startRegion) {

                    // get area infos
                    $areaModel = ModelArea::getArea($id_area);
                    $area = array();
                    $area['id_area'] = $id_area;
                    $area['number'] = $areaModel->getNumber();
                    $area['name'] = $areaModel->getName();

                    // check if country already selected
                    $gameArea = ModelGameArea::getGameAreaForArea(ModelGame::getCurrentGame()->getId(), $id_area);
                    $id_game_area = $gameArea->getId();
                    $modelMove = ModelSelectStartMove::getSelectStartMoveForUser(ModelUser::getCurrentUser()->getId(), ModelGame::getCurrentGame()->getId());
                    if ($modelMove->checkIfAreaIsSelected($option_number, $id_game_area)) {
                        $area['checked'] = true;
                    }

                    $optionViewData['areas'][] = $area;
                }
                $viewData[] = $optionViewData;
            }
        }
        $data['options'] = $viewData;
    }

}
