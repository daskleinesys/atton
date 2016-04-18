<?php
namespace AttOn\Controller\Logic\Operations;

use AttOn\Controller\Game\InGame\LandMoveController;
use AttOn\Controller\Logic\Operations\Interfaces\PhaseLogic;
use AttOn\Exceptions\ControllerException;
use AttOn\Exceptions\LogicException;
use AttOn\Model\Atton\InGame\ModelGameArea;
use AttOn\Model\Atton\InGame\ModelInGameLandUnit;
use AttOn\Model\Atton\InGame\Moves\ModelLandMove;
use AttOn\Model\Atton\ModelLandUnit;
use AttOn\Model\Game\ModelGame;

class LogicLandMove extends PhaseLogic {
    private $logger;

    private $troop_moves = array(); // array (int $id_move)
    private $attack_moves_to = array(); // array (int $id_start_country => array(int $id_move))
    private $finished_moves = array(); // array (int $id_move)

    /**
     * returns object to run game logic -> should only be called by factory
     *
     * @param $id_game int
     * @return LogicLandMove
     */
    public function __construct($id_game) {
        parent::__construct($id_game, PHASE_LANDMOVE);
        $this->logger = \Logger::getLogger('LogicLandMove');
    }

    /**
     * run the game logic
     *
     * @throws LogicException
     * @return void
     */
    public function run() {
        if (!$this->checkIfValid()) {
            throw new LogicException('Game ' . $this->id_game . ' not valid for processing.');
        }
        $this->startProcessing();

        try {
            /*
             * 1. run through all moves
             * 1.a validate moves
             * 1.b sort moves into two groups: troop movements / attacks
             */
            $this->sortAndValidateMoves();

            /*
             * 2. execute troop movements
             */
            foreach ($this->troop_moves as $id_move) {
                $this->executeTroopMovement($id_move);
            }

            /*
             * 3. check for NML-fights
             * 3.a run through all attacks and check if there are mirror moves (for nml-fights)
             * 3.b execute nml-fights
             * 3.c create temporary moves for winner
             */
            $this->checkForNMLFight();

            /*
             * 4. execute remaining fights
             */
            foreach ($this->attack_moves_to as $id_target_area => $moves) {
                $this->executeAttack($id_target_area, $moves);
            }

            /*
             * 5. check for empty areas and revert to neutral if any found
             */
            $this->checkForAbandonedAreas();

            // TODO : remove after dev and add finishProcessing
            throw new \Exception('rollback, still developing');
            //$this->finishProcessing();

        } catch (\Exception $ex) {
            $this->logger->fatal($ex);
            $this->rollback();
        }
    }

    private function sortAndValidateMoves() {
        $game = ModelGame::getGame($this->id_game);
        $round = $game->getRound();
        $move_iter = ModelLandMove::iterator(null, $this->id_game, $round);
        $controllerForUser = array();
        $controller = null;

        // run through moves
        while ($move_iter->hasNext()) {
            /* @var $move ModelLandMove */
            $move = $move_iter->next();
            $id_move = $move->getId();
            $id_user = $move->getIdUser();

            // validate moves
            if (!isset($controllerForUser[$id_user])) {
                $controllerForUser[$id_user] = new LandMoveController($id_user, $this->id_game);
            }
            try {
                $controller = $controllerForUser[$id_user];
                /* @var $controller LandMoveController */
                $controller->validateLandMoveByid($id_move);
            } catch (ControllerException $ex) {
                $this->logger->error($ex);
                $move->flagMoveDeleted();
                continue;
            }

            // sort moves
            $steps = $move->getSteps();
            $zArea = ModelGameArea::getGameArea($this->id_game, end($steps));
            if ($zArea->getIdUser() !== $id_user) {
                if (!isset($this->attack_moves_to[end($steps)])) {
                    $this->attack_moves_to[end($steps)] = array();
                }
                $this->attack_moves_to[end($steps)][] = $id_move;
            } else {
                $this->troop_moves[] = $id_move;
            }
        }
    }

    private function executeTroopMovement($id_move) {
        $move = ModelLandMove::getLandMove($this->id_game, $id_move);
        $id_user = $move->getIdUser();
        $steps = $move->getSteps();
        $from = reset($steps);
        $to = end($steps);
        $units = $move->getUnits();
        foreach ($units as $id_unit => $count) {
            $landUnit_from = ModelInGameLandUnit::getModelByIdZAreaUserUnit($this->id_game, $from, $id_user, $id_unit);
            $landUnit_from->addCount($count * -1);
            $landUnit_to = ModelInGameLandUnit::getModelByIdZAreaUserUnit($this->id_game, $to, $id_user, $id_unit);
            $landUnit_to->addCount($count);
        }
        $this->finished_moves[] = $id_move;
    }

    private function checkForNMLFight() {
        // TODO: concept for NML missing -> especially if there are combined attacks
    }

    private function executeAttack($id_target_area, array $moves) {
        // TODO: code attacks

        // 0. init empty arrays for attacker/defender units
        $units_attacker = array();
        $units_defender = array();

        // 1. get units for defender
        $target_area = ModelGameArea::getGameArea($this->id_game, $id_target_area);
        $id_defender = $target_area->getIdUser();
        $iter = ModelLandUnit::iterator();
        while ($iter->hasNext()) {
            /* @var ModelLandUnit $unit */
            $unit = $iter->next();
            $units_attacker[$unit->getId()] = 0;
            $landUnit_defender = ModelInGameLandUnit::getModelByIdZAreaUserUnit($this->id_game, $id_target_area, $id_defender, $unit->getId());
            $units_defender[$unit->getId()] = $landUnit_defender->getCount();
        }

        // 2. add up all units for attacker (multiple moves possible, check already finished moves from NML-fights)
        // 2.a subtract units from originating country
        foreach ($moves as $id_move) {
            $move = ModelLandMove::getLandMove($this->id_game, $id_move);
            $id_user = $move->getIdUser();
            $steps = $move->getSteps();
            $from = reset($steps);
            $units = $move->getUnits();
            foreach ($units as $id_unit => $count) {
                $landUnit_from = ModelInGameLandUnit::getModelByIdZAreaUserUnit($this->id_game, $from, $id_user, $id_unit);
                $landUnit_from->addCount($count * -1);
                $units_attacker[$id_unit] += $count;
            }
        }

        // 3. calculate winner and remaining units

        // 4. update target country units (and user if attacker won)

        // 4. flag all moves as finished
    }

    private function checkForAbandonedAreas() {
        // TODO : do not run through moves, check all countries if empty
        return;

        $move = ModelLandMove::getLandMove($this->id_game, $id_move);
        $steps = $move->getSteps();
        $from = reset($steps);
        if (in_array($from, $this->checked_areas)) {
            return;
        } else {
            $this->checked_areas[] = $from;
        }
        $zArea = ModelGameArea::getGameArea($this->id_game, $from);
        $id_user = $zArea->getIdUser();
        $units = ModelInGameLandUnit::getUnitsByIdZAreaUser($this->id_game, $from, $id_user);
        $count = 0;
        /* @var $landUnit ModelInGameLandUnit */
        foreach ($units as $landUnit) {
            $count += $landUnit->getCount();
        }
        if ($count > 0) {
            return;
        }

        // unit count <= 0 -> remove country to neutral
        $zArea = ModelGameArea::getGameArea($this->id_game, $from);
        $zArea->setIdUser(NEUTRAL_COUNTRY);
        // TODO : reset unit count
    }

}
