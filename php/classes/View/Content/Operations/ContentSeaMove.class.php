<?php
namespace Attack\View\Content\Operations;

use Attack\Controller\Game\Moves\SeaMoveController;
use Attack\Exceptions\ControllerException;
use Attack\Exceptions\NullPointerException;
use Attack\Model\Game\ModelGame;
use Attack\Model\Game\ModelGameArea;
use Attack\Model\Game\ModelGameShip;
use Attack\Model\Game\Moves\ModelSeaMove;
use Attack\Model\Units\ModelShip;
use Attack\Model\User\ModelUser;
use Attack\View\Content\Operations\Interfaces\ContentOperation;

class ContentSeaMove extends ContentOperation {

    public function getTemplate() {
        return 'seamove';
    }

    public function run(array &$data) {
        $data['template'] = $this->getTemplate();
        $this->addCurrentGameInfo($data);

        if (!$this->checkFixate($data, PHASE_SEAMOVE)) {
            $this->handleInput($data);
        }
        $this->showShips($data);
        $this->showTargetAreas($data);
        $this->checkCurrentPhase($data, PHASE_SEAMOVE);
    }

    private function showShips(array &$data) {
        $ships = [];
        $iterator = ModelGameShip::getAllShips(ModelUser::getCurrentUser()->getId(), ModelGame::getCurrentGame()->getId());
        while ($iterator->hasNext()) {
            /** @var ModelGameShip $ship */
            $ship = $iterator->next();
            if ($ship->getIdGameArea() === NO_AREA) {
                continue;
            }
            $currentArea = ModelGameArea::getGameArea(ModelGame::getCurrentGame()->getId(), $ship->getIdGameArea());
            $shipViewData = [
                'id' => $ship->getId(),
                'name' => $ship->getName(),
                'type' => ModelShip::getModelById($ship->getIdUnit())->getName(),
                'currentArea' => [
                    'id' => $currentArea->getId(),
                    'name' => $currentArea->getName(),
                    'number' => $currentArea->getNumber()
                ]
            ];
            if ($ship->getIdGameAreaInPort() !== NO_AREA) {
                $currentPortArea = ModelGameArea::getGameArea(ModelGame::getCurrentGame()->getId(), $ship->getIdGameAreaInPort());
                $shipViewData['currentPortArea'] = [
                    'id' => $currentPortArea->getId(),
                    'name' => $currentPortArea->getName(),
                    'number' => $currentPortArea->getNumber()
                ];
            }

            try {
                $round = ModelGame::getCurrentGame()->getRound();
                $phase = ModelGame::getCurrentGame()->getIdPhase();
                if ($phase > PHASE_SEAMOVE) {
                    ++$round;
                }
                $move = ModelSeaMove::getByShipId(ModelGame::getCurrentGame()->getId(), $round, $ship->getId());
                $moveSteps = $move->getSteps();
                $targetArea = ModelGameArea::getGameArea(ModelGame::getCurrentGame()->getId(), $moveSteps[2][0]);
                $shipViewData['targetArea'] = [
                    'id' => $targetArea->getId(),
                    'name' => $targetArea->getName(),
                    'number' => $targetArea->getNumber()
                ];
                if ($moveSteps[2][1] !== NO_AREA) {
                    $targetPortArea = ModelGameArea::getGameArea(ModelGame::getCurrentGame()->getId(), $moveSteps[2][1]);
                    $shipViewData['targetPortArea'] = [
                        'id' => $targetPortArea->getId(),
                        'name' => $targetPortArea->getName(),
                        'number' => $targetPortArea->getNumber()
                    ];
                }
            } catch (NullPointerException $ex) {
                $shipViewData['targetArea'] = $shipViewData['currentArea'];
                if (isset($shipViewData['currentPortArea'])) {
                    $shipViewData['targetPortArea'] = $shipViewData['currentPortArea'];
                }
            }
            $ships[] = $shipViewData;
        }
        $data['ships'] = $ships;
    }

    private function showTargetAreas(array &$data) {
        $destination_areas = [];
        $destination_port_areas = [];
        $iterator = ModelGameArea::iterator(null, ModelGame::getCurrentGame()->getId());
        while ($iterator->hasNext()) {
            /** @var ModelGameArea $gameArea */
            $gameArea = $iterator->next();
            if ($gameArea->getIdType() === TYPE_LAND) {
                $destination_port_areas[] = [
                    'id' => $gameArea->getId(),
                    'name' => $gameArea->getName(),
                    'number' => $gameArea->getNumber()
                ];
            } else {
                $destination_areas[] = [
                    'id' => $gameArea->getId(),
                    'name' => $gameArea->getName(),
                    'number' => $gameArea->getNumber()
                ];
            }
        }
        $data['destinationAreas'] = $destination_areas;
        $data['destinationPortAreas'] = $destination_port_areas;
    }

    private function handleInput(array &$data) {
        if (empty($_POST)) {
            return;
        }
        $controller = new SeaMoveController(ModelUser::getCurrentUser()->getId(), ModelGame::getCurrentGame()->getId());

        // fixating sea move
        if (isset($_POST['fixate_sea_move'])) {
            $controller->finishMove();
            $this->checkFixate($data, PHASE_SEAMOVE);
            return;
        }

        // creating/updating sea moves
        if (!isset($_POST['set_seamove'])) {
            return;
        }
        $iterator = ModelGameShip::getAllShips(ModelUser::getCurrentUser()->getId(), ModelGame::getCurrentGame()->getId());
        while ($iterator->hasNext()) {
            /** @var ModelGameShip $ship */
            $ship = $iterator->next();
            if ($ship->getIdGameArea() === NO_AREA) {
                continue;
            }
            $desinationAreaKey = 'destination-area-' . $ship->getId();
            $desinationPortAreaKey = 'destination-port-area-' . $ship->getId();
            if (!isset($_POST[$desinationAreaKey]) || !isset($_POST[$desinationPortAreaKey])) {
                continue;
            }
            try {
                $controller->setMoveForShip($ship, (int)$_POST[$desinationAreaKey], (int)$_POST[$desinationPortAreaKey]);
                $data['status'] = [
                    'message' => 'Seezug angepasst.'
                ];
            } catch (NullPointerException $ex) {
                $data['errors'] = [
                    'message' => $ex->getMessage()
                ];
            } catch (ControllerException $ex) {
                $data['errors'] = [
                    'message' => $ex->getMessage()
                ];
            }
        }
    }

}
