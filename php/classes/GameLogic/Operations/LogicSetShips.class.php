<?php
namespace Attack\GameLogic\Operations;

use Attack\Controller\Game\Moves\SetShipsController;
use Attack\GameLogic\Operations\Interfaces\PhaseLogic;
use Attack\Exceptions\ControllerException;
use Attack\Exceptions\LogicException;
use Attack\Model\Game\ModelGameShip;
use Attack\Model\Game\Moves\ModelSetShipsMove;

class LogicSetShips extends PhaseLogic {

    private $logger;

    /**
     * returns object to run game logic -> should only be called by factory
     * @param $id_game int
     */
    public function __construct($id_game) {
        parent::__construct($id_game, PHASE_SETSHIPS);
        $this->logger = \Logger::getLogger('LogicSetShips');
    }

    /**
     * run the game logic
     *
     * @throws LogicException
     * @return void
     */
    public function run() {
        if (!$this->checkIfValid()) {
            throw new LogicException('Game '.$this->id_game.' not valid for processing.');
        }
        $this->startProcessing();

        try {
            $controllerForUser = array();
            $controller = null;

            // run through moves for each user and validate
            $iter = ModelSetShipsMove::iterator($this->id_game);
            while ($iter->hasNext()) {
                /* @var $move ModelSetShipsMove */
                $move = $iter->next();
                $id_user = $move->getIdUser();

                // validate moves
                if (!isset($controllerForUser[$id_user])) {
                    $controllerForUser[$id_user] = $controller = new SetShipsController($id_user, $this->id_game);
                }
                try {
                    $controller->validateSetShipsMove($move);
                } catch (ControllerException $ex) {
                    $this->logger->error($ex);
                    $move->flagMoveDeleted();
                    ModelGameShip::deleteShip($this->id_game, $move->getIdGameUnit());
                    continue;
                }

                $ship = ModelGameShip::getShipById($this->id_game, $move->getIdGameUnit());
                $ship->setIdGameArea($move->getIdGameArea());
                $ship->setIdGameAreaInPort($move->getIdGameAreaInPort());
            }

            $this->finishProcessing();
        } catch (\Exception $ex) {
            $this->logger->fatal($ex);
            $this->rollback();
        }
    }

}
