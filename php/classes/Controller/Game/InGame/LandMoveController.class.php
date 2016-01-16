<?php

class LandMoveController extends PhaseController {
	private $_Logger;
	private $error = false;
	
	/**
	 * @param int $id_user - id of the user accessing the moderation actions
	 * @return LandMoveController
	 */
	public function __construct($id_user,$id_game) {
		parent::__construct($id_user,$id_game,PHASE_LANDMOVE);
		$this->_Logger = Logger::getLogger('LandMoveController');
	}

	/**
	 * delete land move for user
	 * @param int $id_move
	 * @throws NullPointerException, ControllerException
	 * @return void
	 */
	public function deleteLandMove($id_move) {
		$id_move = intval($id_move);
		
		// check if move exists
		$_Move = ModelLandMove::getLandMove($this->id_game, $id_move);
		// check if move is from user
		if ($this->id_user != $_Move->getIdUser()) throw new ControllerException('Unable to delete move from another user.');
		// check if already fixated
		if ($this->checkIfDone()) throw new ControllerException('LandMove already finished.');
		// check if processing
		$_Game = ModelGame::getGame($this->id_game);
		if ($_Game->checkProcessing()) throw new ControllerException('Unable to delete moves at this moment as the game-logic is currently processing.');
		// check if move is a landmove from the current round
		$move_round = $_Move->getRound();
		$round = $_Game->getRound();
		$phase = $_Game->getIdPhase();
		if ($phase > PHASE_LANDMOVE) $round++;
		if ($round != $move_round) throw new ControllerException('Unable to delete move as it is not from the correct round.');
		
		// delete move
		ModelLandMove::deleteMove($this->id_game, $id_move);
		
		return;
	}
	
	/**
	 * create land move for user
	 * @param int $start (id_zarea)
	 * @param int $destination (id_zarea)
	 * @param array $units (int $id_unit -> $int count)
	 * @throws NullPointerException, ControllerException
	 * @return void
	 */
	public function createLandMove($start,$destination,$units) {
		$start = intval($start);
		$destination = intval($destination);
		// check if already fixated
		if ($this->checkIfDone()) throw new ControllerException('LandMove already finished.');
		// check if processing
		$_Game = ModelGame::getGame($this->id_game);
		if ($_Game->checkProcessing()) throw new ControllerException('Unable to create moves at this moment as the game-logic is currently processing.');
		// check if countries picked
		if ($start == 0 || $destination == 0) throw new ControllerException('Choose a start and destination country.');
		// check for units
		$unit_count = 0;
		foreach ($units as $count) {
			if ($count < 0) throw new ControllerException('No negative unit numbers allowed.');
			$unit_count += $count;
		}
		if ($unit_count == 0) throw new ControllerException('Choose at least one unit.');
		// check if start and destination is the same
		if ($start == $destination) throw new ControllerException('Units have to move at least 1 country.');
		
		$steps = array();
		$steps[1] = $start;
		$steps[2] = $destination;
		
		$round = $_Game->getRound();
		$phase = $_Game->getIdPhase();
		if ($phase > PHASE_LANDMOVE) $round++;
		
		try {
			$this->validateLandMove(0, $round, $steps, $units);
		} catch (NullPointerException $ex) {
			throw $ex;
		} catch (ControllerException $ex) {
			throw $ex;
		}
		
		try {
			ModelLandMove::createLandMove($this->id_game, $this->id_user, $round, $steps, $units);
		} catch (NullPointerException $ex) {
			throw $ex;
		}
	}

	/**
	 * checks if this move is valid, throws exception if not valid
	 * @param int $id_move
	 * @throws NullPointerException,ControllerException
	 * @return boolean
	 */
	public function validateLandMoveByid($id_move) {
		$id_move = intval($id_move);
		
		// check if move is not already over
		$_Game = ModelGame::getGame($this->id_game);
		$round = $_Game->getRound();
		$phase = $_Game->getIdPhase();
		if ($phase > PHASE_LANDMOVE) $round++;
		
		$_Move = ModelLandMove::getLandMove($this->id_game, $id_move);
		$move_round = $_Move->getRound();
		if ($round != $move_round) throw new ControllerException('Cannot validate move as it is not from the correct round.');
		
		return $this->validateLandMove($id_move, $round, $_Move->getSteps(), $_Move->getUnits());
	}
	
	/**
	 * checks if this move is valid, throws exception if not valid
	 * @param int $id_move
	 * @param int $round
	 * @param $steps array(int step_nr => int id_zarea) -> step_nr counting from 1 to x
	 * @param $units array(int id_unit => count)
	 * @throws NullPointerException,ControllerException
	 * @return boolean
	 */
	private function validateLandMove($id_move,$round,$steps,$units) {
		$attacks = array();
		
		/*
		 * check if user owns the start country
		 */
		$id_start_area = $steps[1];
		$_ZArea = ModelGameArea::getGameArea($this->id_game, $id_start_area);
		if ($_ZArea->getIdUser() != $this->id_user) throw new ControllerException('Start country isn\'t owned by this user.');
		
		/*
		 * check if target area is land area
		 */
		if ($_ZArea->getIdType() != TYPE_LAND) throw new ControllerException('Start area not a country.');
		
		/*
		 * check if enough units are left in the country -> iterate over all moves (except this) and substract outgoing units from country units
		 */
		$_Ingame_Unit_Models = ModelInGameLandUnit::getUnitsByIdZAreaUser($this->id_game, $id_start_area, $this->id_user); //array(int id_unit => ModelInGameLandUnit)
		$area_units = array();
		$iter = ModelLandUnit::iterator();
		while ($iter->hasNext()) {
			$_Unit = $iter->next();
			$id_unit = $_Unit->getId();
			$area_units[$id_unit] = (isset($_Ingame_Unit_Models[$id_unit])) ? $_Ingame_Unit_Models[$id_unit]->getCount() : 0;
		}
		$iter_moves = ModelLandMove::iterator($this->id_game,$round,$this->id_user);
		while ($iter_moves->hasNext()) {
			$_Move = $iter_moves->next();
			if ($_Move->getId() == $id_move) continue; // only subtract units from other moves
			
			$move_steps = $_Move->getSteps();
			$_ZArea = ModelGameArea::getGameArea($this->id_game, $move_steps[count($move_steps)]);
			if ($_ZArea->getIdUser() != $this->id_user && (!in_array($_ZArea->getId(), $attacks))) $attacks[] = $_ZArea->getId(); // check if this is an attack
			if ($move_steps[1] != $id_start_area) continue; // only subtract units outgoing from the same area
			
			$move_units = $_Move->getUnits();
			foreach ($move_units as $id_unit => $count) {
				$area_units[$id_unit] -= $count;
			}
		}
		foreach ($area_units as $id_unit => $count) {
			if (isset($units[$id_unit]) && $units[$id_unit] > $count) throw new ControllerException('Invalid move, not enough units in area.');
		}
		
		/*
		 * check if target area is reachable -> 2 cases: type land or type aircraft movement
		 */
		$id_target_area = $steps[count($steps)];
		$type = TYPE_AIR;
		$speed = 99999;
		foreach ($units as $id_unit => $count) {
			if ($count <= 0) continue;
			$_Unit = ModelLandUnit::getModelById($id_unit);
			if ($_Unit->getIdType() < $type) $type = $_Unit->getIdType();
			if ($_Unit->getSpeed() < $speed) $speed = $_Unit->getSpeed();
		}
		if (!$this->checkPossibleRoute($id_start_area, $id_target_area, $type, $speed)) throw new ControllerException('Unable to find route between the 2 countries.');
		
		/*
		 * check if target area is enemy area, only MAX_LAND_ATTACKS attacks per round
		 */
		$_ZArea = ModelGameArea::getGameArea($this->id_game, $id_target_area);
		if ($_ZArea->getIdUser() != $this->id_user) { // move is an attack
			if (!in_array($_ZArea->getId(), $attacks)) {
				$attacks[] = $_ZArea->getId();
			}
			if (count($attacks) > MAX_LAND_ATTACKS) {
				throw new ControllerException('Unable to start any more attacks! Only '.MAX_LAND_ATTACKS.' per round allowed.');
			}
		}
		
		/*
		 * check if target area is land area
		 */
		if ($_ZArea->getIdType() != TYPE_LAND) throw new ControllerException('Destination not a country.');
		
		return true;
	}

	/**
	 * checks if there is a possible route for this 2 countries for the given user
	 */
	public function checkPossibleRoute($id_start,$id_destination,$type,$speed) {
		$steps = 0;
		$next = array($id_start);
		$current = array();
		$visited = array();
		
		while (true) {
			$current = $next;
			$next = array();
			if ($steps > $speed) return false;
			if (empty($current)) return false;
			foreach ($current as $id_zarea) {
				if ($id_zarea == $id_destination) return true; // destination found
				$visited[] = $id_zarea;
				if (!$this->isAreaPassable($id_zarea, $type)) continue;
				$_ZArea = ModelGameArea::getGameArea($this->id_game, $id_zarea);
				$a2a = $_ZArea->getAdjecents();
				foreach ($a2a as $id_a2a_zarea) {
					if (!in_array($id_a2a_zarea, $visited) && !in_array($id_a2a_zarea, $current) && !in_array($id_a2a_zarea, $next)) $next[] = $id_a2a_zarea;
				}
			}
			$steps++;
		}
	}
	
	private function isAreaPassable($id_zarea,$type) {
		$_ZArea = ModelGameArea::getGameArea($this->id_game, $id_zarea);
		$id_owner = $_ZArea->getIdUser();
		$area_type = $_ZArea->getIdType();
		
		if ($area_type == TYPE_LAND && $id_owner == $this->id_user) return true;
		//if (($area_type == TYPE_SEA) && ($type == TYPE_AIR) && (($id_owner == $this->id_user) || ($id_owner == NEUTRAL_COUNTRY))) return true;
		if (($area_type == TYPE_SEA) && ($type == TYPE_AIR) && ($id_owner == $this->id_user)) return true;
		if (($area_type == TYPE_SEA) && ($type == TYPE_AIR) && ($id_owner == NEUTRAL_COUNTRY)) return true;
		
		return false;
	}
	
	/**
	 * fixates the move if no error occured
	 * @return void
	 */
	public function finishMove() {
		$this->fixatePhase(true);
	}
}

?>