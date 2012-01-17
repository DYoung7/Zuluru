<?php
class DivisionsController extends AppController {

	var $name = 'Divisions';
	var $helpers = array('ZuluruGame');
	var $components = array('Lock', 'CanRegister');

	function isAuthorized() {
		if (in_array ($this->params['action'], array(
				'view',
				'schedule',
				'standings',
				'scores',
		)))
		{
			return true;
		}

		// People can perform these operations on divisions they coordinate
		if (in_array ($this->params['action'], array(
				'edit',
				'approve_scores',
				'fields',
				'slots',
				'status',
				'allstars',
				'emails',
				'spirit',
				'ratings',
				'validate_ratings',
				'initialize_ratings',
				'initialize_dependencies',
		)))
		{
			// If a division id is specified, check if we're a coordinator of that division
			$division = $this->_arg('division');
			if ($division && in_array ($division, $this->Session->read('Zuluru.DivisionIDs'))) {
				return true;
			}
		}

		return false;
	}

	function view() {
		$id = $this->_arg('division');
		if (!$id) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues'));
		}

		$this->Division->contain(array (
			'Person',
			'Day' => array('order' => 'day_id'),
			'Team' => array ('Person', 'Franchise'),
			'League',
			'Event' => 'EventType',
		));
		$division = $this->Division->read(null, $id);
		if ($division === false) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}
		$this->Division->addPlayoffs($division);

		// Find all games played by teams that are currently in this division,
		// or tournament games for this division
		$teams = Set::extract ('/Team/id', $division);
		$this->Division->Game->contain (array('GameSlot', 'SpiritEntry'));
		$division['Game'] = $this->Division->Game->find('all', array(
				'conditions' => array(
					'OR' => array(
						'Game.home_team' => $teams,
						'Game.away_team' => $teams,
						'AND' => array(
							'Game.division_id' => $id,
							'Game.tournament' => true,
						),
					),
				),
		));

		$league_obj = $this->_getComponent ('LeagueType', $division['Division']['schedule_type'], $this);
		$league_obj->sort($division);

		if ($division['Division']['is_playoff']) {
			foreach ($division['Team'] as $key => $team) {
				$affiliate_id = $this->_getAffiliateId($division['Division'], $team);
				if ($affiliate_id !== null) {
					$this->Division->Team->contain('Division');
					$affiliate = $this->Division->Team->read(null, $affiliate_id);
					$division['Team'][$key]['affiliate_division'] = $affiliate['Division']['name'];
				}
			}
		}

		// Eliminate any events that cannot be registered for
		$my_id = $this->Auth->user('id');
		if ($my_id) {
			foreach ($division['Event'] as $key => $event) {
				$test = $this->CanRegister->test ($my_id, array('Event' => $event));
				if (!$test['allowed']) {
					unset ($division['Event'][$key]);
				}
			}
		}

		$this->set(compact ('division', 'league_obj'));
		$this->set('is_coordinator', in_array($id, $this->Session->read('Zuluru.DivisionIDs')));

		$this->_addDivisionMenuItems ($this->Division->data['Division'], $this->Division->data['League']);
	}

	function add() {
		if (!empty($this->data)) {
			$this->Division->create();
			if ($this->Division->save($this->data)) {
				$this->Session->setFlash(sprintf(__('The %s has been saved', true), __('division', true)), 'default', array('class' => 'success'));
				$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
			} else {
				$this->Session->setFlash(sprintf(__('The %s could not be saved. Please correct the errors below and try again.', true), __('division', true)), 'default', array('class' => 'warning'));
			}
		} else if ($this->_arg('division')) {
			// To clone a division, read the old one and remove the id
			$this->Division->contain('Day');
			$this->data = $this->Division->read(null, $this->_arg('division'));
			if (!$this->data) {
				$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
				$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
			}
			unset($this->data['Division']['id']);
		}

		if ($this->_arg('league')) {
			$this->data['Division']['league_id'] = $this->_arg('league');
		}

		$leagues = $this->Division->League->find('all', array(
				'conditions' => array('OR' => array(
					'League.is_open' => true,
					'League.open > NOW()',
				)),
				'contain' => false,
		));
		usort ($leagues, array('League', 'compareLeagueAndDivision'));
		$this->set('leagues', Set::combine($leagues, '{n}.League.id', '{n}.League.full_name'));

		$this->set('days', $this->Division->Day->find('list'));
		if (isset($this->data['Division']['schedule_type'])) {
			$this->set('league_obj', $this->_getComponent ('LeagueType', $this->data['Division']['schedule_type'], $this));
		}
		$this->set('is_coordinator', false);
		$this->set('add', true);
		$this->render ('edit');
	}

	function edit() {
		$id = $this->_arg('division');
		if (!$id && empty($this->data)) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}
		if (!empty($this->data)) {
			if ($this->Division->saveAll($this->data)) {
				$this->Session->setFlash(sprintf(__('The %s has been saved', true), __('division', true)), 'default', array('class' => 'success'));
				$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
			} else {
				$this->Session->setFlash(sprintf(__('The %s could not be saved. Please correct the errors below and try again.', true), __('division', true)), 'default', array('class' => 'warning'));
			}
		}
		if (empty($this->data)) {
			$this->Division->contain(array (
				'Day' => array('order' => 'day_id'),
				'League',
			));
			$this->data = $this->Division->read(null, $id);
		}

		$leagues = $this->Division->League->find('all', array(
				'conditions' => array('OR' => array(
					'League.is_open' => true,
					'League.open > NOW()',
				)),
				'contain' => false,
		));
		usort ($leagues, array('League', 'compareLeagueAndDivision'));
		$this->set('leagues', Set::combine($leagues, '{n}.League.id', '{n}.League.full_name'));

		$this->set('days', $this->Division->Day->find('list'));
		$this->set('league_obj', $this->_getComponent ('LeagueType', $this->data['Division']['schedule_type'], $this));
		$this->set('is_coordinator',
			array_key_exists ('DivisionsPerson', $this->data['Division']) &&
			array_key_exists ('position', $this->data['Division']['DivisionsPerson']) &&
			$this->data['Division']['DivisionsPerson']['position'] == 'coordinator');

		$this->_addDivisionMenuItems ($this->Division->data['Division'], $this->Division->data['League']);
	}

	function scheduling_fields() {
		Configure::write ('debug', 0);
		$this->layout = 'ajax';
		$this->set('league_obj', $this->_getComponent ('LeagueType', $this->params['url']['data']['Division']['schedule_type'], $this));
	}

	function add_coordinator() {
		$id = $this->_arg('division');
		if (!$id) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}

		$this->Division->contain('Person', 'League');
		$division = $this->Division->read(null, $id);
		if ($division === false) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}

		$this->set(compact('division'));

		$person_id = $this->_arg('person');
		if ($person_id != null) {
			$this->Division->Person->contain(array('Division' => array('conditions' => array('Division.id' => $id))));
			$person = $this->Division->Person->read(null, $person_id);
			if (!empty ($person['Division'])) {
				$this->Session->setFlash(__("{$person['Person']['full_name']} is already a coordinator of this division", true), 'default', array('class' => 'info'));
			} else {
				$division['Person'] = Set::extract ('/Person/id', $division);
				$division['Person'][] = $person['Person']['id'];
				// TODO: If we add more coordinator types, we need to save the position here
				if ($this->Division->saveAll ($division)) {
					$this->Session->setFlash(__("Added {$person['Person']['full_name']} as coordinator", true), 'default', array('class' => 'success'));
					$this->redirect(array('action' => 'view', 'division' => $id));
				} else {
					$this->Session->setFlash(__("Failed to add {$person['Person']['full_name']} as coordinator", true), 'default', array('class' => 'warning'));
				}
			}
		}

		$params = $url = $this->_extractSearchParams();
		unset ($params['division']);
		unset ($params['person']);
		if (!empty($params)) {
			$test = trim ($params['first_name'], ' *') . trim ($params['last_name'], ' *');
			if (strlen ($test) < 2) {
				$this->set('short', true);
			} else {
				// This pagination needs the model at the top level
				$this->Person = $this->Division->Person;
				$this->_mergePaginationParams();
				$this->paginate['Person'] = array(
					'conditions' => array_merge (
						$this->_generateSearchConditions($params, 'Person'),
						array('Group.name' => array('Volunteer', 'Administrator'))
					),
					'contain' => array('Group', 'Upload'),
				);
				$this->set('people', $this->paginate('Person'));
			}
		}
		$this->set(compact('url'));
	}

	function remove_coordinator() {
		$id = $this->_arg('division');
		if (!$id) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}
		$person_id = $this->_arg('person');
		if (!$person_id) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('person', true)), 'default', array('class' => 'info'));
			$this->redirect(array('action' => 'view', 'division' => $id));
		}

		$join = ClassRegistry::init('DivisionsPerson');
		if ($join->deleteAll (array('division_id' => $id, 'person_id' => $person_id))) {
			$this->Session->setFlash(__('Successfully removed coordinator', true), 'default', array('class' => 'success'));
		} else {
			$this->Session->setFlash(__('Failed to remove coordinator!', true), 'default', array('class' => 'warning'));
		}
		$this->redirect(array('action' => 'view', 'division' => $id));
	}

	function ratings() {
		$id = $this->_arg('division');
		if (!$id) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}

		if (!empty($this->data)) {
			if ($this->Division->Team->saveAll($this->data['Team'])) {
				$this->Session->setFlash(sprintf(__('The %s has been saved', true), __('division', true)), 'default', array('class' => 'success'));
				$this->redirect(array('action' => 'view', 'division' => $id));
			} else {
				$this->Session->setFlash(sprintf(__('The %s could not be saved. Please correct the errors below and try again.', true), __('division', true)), 'default', array('class' => 'warning'));
			}
		}

		$this->Division->contain(array (
			'Day' => array('order' => 'day_id'),
			'Team' => array(
				'Person',
				'order' => 'rating DESC',
			),
			'League',
		));
		$division = $this->Division->read(null, $id);
		if ($division === false) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}

		$this->set(compact ('division'));
		$this->_addDivisionMenuItems ($this->Division->data['Division'], $this->Division->data['League']);
	}

	function delete() {
		$id = $this->_arg('division');
		if (!$id) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('action'=>'index'));
		}

		// TODO: Handle deletions
		$this->Session->setFlash(__('Deletions are not currently supported', true), 'default', array('class' => 'info'));
		$this->redirect('/');

		if ($this->Division->delete($id)) {
			$this->Session->setFlash(sprintf(__('%s deleted', true), __('Division', true)), 'default', array('class' => 'success'));
			$this->redirect(array('action'=>'index'));
		}
		$this->Session->setFlash(sprintf(__('%s was not deleted', true), __('Division', true)), 'default', array('class' => 'warning'));
		$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
	}

	function schedule() {
		$id = $this->_arg('division');
		if (!$id) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}

		$is_coordinator = in_array($id, $this->Session->read('Zuluru.DivisionIDs'));
		if ($this->is_admin || $is_coordinator) {
			$edit_date = $this->_arg('edit_date');
			if (!empty ($this->data)) {
				$edit_date = $this->data['Game']['edit_date'];
				unset ($this->data['Game']['edit_date']);
			}
		} else {
			$edit_date = null;
		}

		if ($edit_date) {
			$game_slots = $this->Division->DivisionGameslotAvailability->GameSlot->getAvailable($id, $edit_date);
		}

		// Save posted data
		if (!empty ($this->data) && ($this->is_admin || $is_coordinator)) {
			if ($this->_validateAndSaveSchedule($game_slots)) {
				$this->redirect (array('action' => 'schedule', 'division' => $id));
			}
		}

		$this->Division->contain(array (
			'Day' => array('order' => 'day_id'),
			'Team',
			'League',
			'Game' => array(
				'GameSlot' => array('Field' => 'Facility'),
				'ScoreEntry' => array('conditions' => array('ScoreEntry.team_id' => $this->Session->read('Zuluru.TeamIDs'))),
				// Get the list of captains for each team, for the popup
				'HomeTeam' => array(
					'Person' => array(
						'conditions' => array('TeamsPerson.position' => Configure::read('privileged_roster_positions')),
						'fields' => array('id', 'first_name', 'last_name'),
					),
				),
				'AwayTeam' => array(
					'Person' => array(
						'conditions' => array('TeamsPerson.position' => Configure::read('privileged_roster_positions')),
						'fields' => array('id', 'first_name', 'last_name'),
					),
				),
			),
		));
		$division = $this->Division->read(null, $id);
		if ($division === false) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}
		if (empty ($division['Game'])) {
			$this->Session->setFlash(__('This division has no games scheduled yet.', true), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}
		// Sort games by date, time and field
		usort ($division['Game'], array ('Game', 'compareDateAndField'));

		$this->set(compact ('id', 'division', 'edit_date', 'game_slots', 'is_coordinator'));

		$this->_addDivisionMenuItems ($this->Division->data['Division'], $this->Division->data['League']);
	}

	function _validateAndSaveSchedule($available_slots) {
		$publish = $this->data['Game']['publish'];
		unset ($this->data['Game']['publish']);
		$allow_double_header = $this->data['Game']['double_header'];
		unset ($this->data['Game']['double_header']);

		$games = count($this->data['Game']);
		// TODO: Remove workaround for Set::extract bug
		$this->data['Game'] = array_values($this->data['Game']);
		$slots = Set::extract ('/Game/GameSlot/id', $this->data);
		if (in_array ('', $slots)) {
			$this->Session->setFlash(__('You cannot choose the "---" as the game time/place!', true), 'default', array('class' => 'info'));
			return false;
		}

		$slot_counts = array_count_values ($slots);
		foreach ($slot_counts as $slot_id => $count) {
			if ($count > 1) {
				$this->Division->Game->GameSlot->contain(array(
						'Field' => 'Facility',
				));
				$slot = $this->Division->Game->GameSlot->read(null, $slot_id);
				$slot_field = $slot['Field']['long_name'];
				$slot_time = "{$slot['GameSlot']['game_date']} {$slot['GameSlot']['game_start']}";
				$this->Session->setFlash(sprintf (__('Game slot at %s on %s was selected more than once!', true), $slot_field, $slot_time), 'default', array('class' => 'info'));
				return false;
			}
		}

		$teams = array_merge (
				Set::extract ('/Game/home_team', $this->data),
				Set::extract ('/Game/away_team', $this->data)
		);
		if (in_array ('', $teams)) {
			$this->Session->setFlash(__('You cannot choose the "---" as the team!', true), 'default', array('class' => 'info'));
			return false;
		}

		$team_counts = array_count_values ($teams);
		foreach ($team_counts as $team_id => $count) {
			if ($count > 1) {
				$this->Division->Team->recursive = -1;
				$team = $this->Division->Team->read(null, $team_id);

				if ($allow_double_header) {
					// Check that the double-header doesn't cause conflicts; must be at the same site, but different times
					$team_slot_ids = array_merge(
						Set::extract ("/Game[home_team=$team_id]/GameSlot/id", $this->data),
						Set::extract ("/Game[away_team=$team_id]/GameSlot/id", $this->data)
					);
					if (count ($team_slot_ids) != count (array_unique ($team_slot_ids))) {
						$this->Session->setFlash(sprintf (__('Team %s was scheduled twice in the same time slot!', true), $team['Team']['name']), 'default', array('class' => 'info'));
						return false;
					}

					$this->Division->Game->GameSlot->contain(array(
							'Field',
					));
					$team_slots = $this->Division->Game->GameSlot->find('all', array('conditions' => array(
							'GameSlot.id' => $team_slot_ids,
					)));
					foreach ($team_slots as $key1 => $slot1) {
						foreach ($team_slots as $key2 => $slot2) {
							if ($key1 != $key2) {
								if ($slot1['GameSlot']['game_date'] == $slot2['GameSlot']['game_date'] &&
									$slot1['GameSlot']['game_start'] >= $slot2['GameSlot']['game_start'] &&
									$slot1['GameSlot']['game_start'] < $slot2['GameSlot']['display_game_end'])
								{
									$this->Session->setFlash(sprintf (__('Team %s was scheduled in overlapping time slots!', true), $team['Team']['name']), 'default', array('class' => 'info'));
									return false;
								}
								if ($slot1['Field']['facility_id'] != $slot2['Field']['facility_id']) {
									$this->Session->setFlash(sprintf (__('Team %s was scheduled on fields at different facilities!', true), $team['Team']['name']), 'default', array('class' => 'info'));
									return false;
								}
							}
						}
					}
				} else {
					$this->Session->setFlash(sprintf (__('Team %s was selected more than once!', true), $team['Team']['name']), 'default', array('class' => 'info'));
					return false;
				}
			}
		}

		if (!$this->Lock->lock ('scheduling', 'schedule creation or edit')) {
			return false;
		}
		if (!$this->Division->Game->_saveGames($this->data['Game'], $publish)) {
			$this->Lock->unlock();
			return false;
		}
		$this->Lock->unlock();

		$unused_slots = array_diff (Set::extract ('/GameSlot/id', $available_slots), $slots);
		if ($this->Division->Game->GameSlot->updateAll (array('game_id' => null), array('GameSlot.id' => $unused_slots))) {
			$this->Session->setFlash(__('Schedule changes saved!', true), 'default', array('class' => 'success'));
			return true;
		} else {
			$this->Session->setFlash(__('Saved schedule changes, but failed to clear unused slots!', true), 'default', array('class' => 'warning'));
			return false;
		}
	}

	// TODO: Remove this entire function once ratings calculations are 100%
	function validate_ratings() {
		$id = $this->_arg('division');
		if (!$id) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}
		$correct = $this->_arg('correct');

		$this->Division->contain(array (
			'Team',
			'League',
		));
		$division = $this->Division->read(null, $id);
		if ($division === false) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}

		// Find all games played by teams that are currently in this division
		$teams = Set::extract ('/Team/id', $division);
		$this->Division->Game->contain (array('GameSlot', 'HomeTeam', 'AwayTeam'));
		$division['Game'] = $this->Division->Game->find('all', array(
				'conditions' => array(
					'OR' => array(
						'home_team' => $teams,
						'away_team' => $teams,
					),
				),
		));

		if (empty ($division['Game'])) {
			$this->Session->setFlash(__('This division has no games scheduled yet.', true), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}
		$league_obj = $this->_getComponent ('LeagueType', $division['Division']['schedule_type'], $this);

		// Sort games by date, time and field
		usort ($division['Game'], array ('Game', 'compareDateAndField'));

		$teams = array();
		foreach ($division['Team'] as $team) {
			$teams[$team['id']] = $team;
		}
		$division['Team'] = $teams;
		$moved_teams = array();
		$game_updates = array();

		foreach ($division['Game'] as $key => $game) {
			// Handle teams that have moved
			if (!array_key_exists ($game['Game']['home_team'], $division['Team'])) {
				$moved_teams[] = $game['Game']['home_team'];
				$division['Team'][$game['Game']['home_team']] = $game['HomeTeam'];
			}
			if (!array_key_exists ($game['Game']['away_team'], $division['Team'])) {
				$moved_teams[] = $game['Game']['away_team'];
				$division['Team'][$game['Game']['away_team']] = $game['AwayTeam'];
			}

			if (!array_key_exists ('current_rating', $division['Team'][$game['Game']['home_team']])) {
				$division['Team'][$game['Game']['home_team']]['current_rating'] = $game['Game']['rating_home'];
			}
			if (!array_key_exists ('current_rating', $division['Team'][$game['Game']['away_team']])) {
				$division['Team'][$game['Game']['away_team']]['current_rating'] = $game['Game']['rating_away'];
			}

			$division['Game'][$key]['Game']['calc_rating_home'] = $division['Team'][$game['Game']['home_team']]['current_rating'];
			$division['Game'][$key]['Game']['calc_rating_away'] = $division['Team'][$game['Game']['away_team']]['current_rating'];

			// Note: We don't check config for whether rating points are transferred on defaults, but this
			// is only being used in the interim for a division where they are, so it's not an issue.
			if ($this->Division->Game->_is_finalized ($game) && $game['Game']['status'] != 'rescheduled') {
				if ($game['Game']['home_score'] >= $game['Game']['away_score']) {
					$division['Game'][$key]['Game']['expected'] = $this->Division->Game->_calculate_expected_win($division['Team'][$game['Game']['home_team']]['current_rating'], $division['Team'][$game['Game']['away_team']]['current_rating']);
					$change = $league_obj->calculateRatingsChange($game['Game']['home_score'], $game['Game']['away_score'], $division['Game'][$key]['Game']['expected']);
					$division['Team'][$game['Game']['home_team']]['current_rating'] += $change;
					$division['Team'][$game['Game']['away_team']]['current_rating'] -= $change;
				} else {
					$division['Game'][$key]['Game']['expected'] = $this->Division->Game->_calculate_expected_win($division['Team'][$game['Game']['away_team']]['current_rating'], $division['Team'][$game['Game']['home_team']]['current_rating']);
					$change = $league_obj->calculateRatingsChange($game['Game']['home_score'], $game['Game']['away_score'], $division['Game'][$key]['Game']['expected']);
					$division['Team'][$game['Game']['home_team']]['current_rating'] -= $change;
					$division['Team'][$game['Game']['away_team']]['current_rating'] += $change;
				}
				$division['Game'][$key]['Game']['calc_rating_points'] = $change;
			} else {
				$division['Game'][$key]['Game']['calc_rating_points'] = $division['Game'][$key]['Game']['expected'] = null;
			}

			// Only save updates for games that actually changed
			$update = array('id' => $game['Game']['id']);
			if ($division['Game'][$key]['Game']['calc_rating_home'] != $game['Game']['rating_home']) {
				$update['rating_home'] = $division['Game'][$key]['Game']['calc_rating_home'];
			}
			if ($division['Game'][$key]['Game']['calc_rating_away'] != $game['Game']['rating_away']) {
				$update['rating_away'] = $division['Game'][$key]['Game']['calc_rating_away'];
			}
			if ($division['Game'][$key]['Game']['calc_rating_points'] != $game['Game']['rating_points']) {
				$update['rating_points'] = $division['Game'][$key]['Game']['calc_rating_points'];
			}
			if (count($update) > 1) {
				$game_updates[] = $update;
			}
		}

		foreach ($division['Team'] as $key => $team) {
			if (!array_key_exists('current_rating', $team)) {
				$division['Team'][$key]['current_rating'] = $division['Team'][$key]['rating'];
			}
		}

		if ($correct && !empty ($game_updates)) {
			$this->Division->Game->saveAll ($game_updates);
		}

		// Remove moved teams, and update the rest
		foreach ($moved_teams as $team) {
			unset ($division['Team'][$team]);
		}
		if ($correct && !empty ($game_updates)) {
			$team_updates = array();
			foreach ($division['Team'] as $key => $team) {
				$team_updates[] = array(
					'id' => $team['id'],
					'rating' => $team['current_rating'],
				);
			}
			$this->Division->Team->saveAll ($team_updates);
		}

		// Find new rankings for each team, and sort by old ranking
		$new = Set::sort (array_values ($division['Team']), '/current_rating', 'DESC');
		foreach ($new as $key => $team) {
			$division['Team'][$team['id']]['rank'] = $key + 1;
		}
		$division['Team'] = Set::sort (array_values ($division['Team']), '/rating', 'DESC');

		$this->set(compact ('id', 'division', 'league_obj', 'correct'));

		$this->_addDivisionMenuItems ($this->Division->data['Division'], $this->Division->data['League']);
	}

	function standings() {
		$id = $this->_arg('division');
		$teamid = $this->_arg('team');
		$showall = $this->_arg('full');
		if (!$id) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}

		$this->Division->contain(array (
			'Day' => array('order' => 'day_id'),
			// Get the list of captains for each team, for the popup
			'Team' => array(
				'Person' => array(
					'conditions' => array('TeamsPerson.position' => Configure::read('privileged_roster_positions')),
					'fields' => array('id', 'first_name', 'last_name'),
				),
			),
			'League',
		));
		$division = $this->Division->read(null, $id);
		if ($division === false) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}

		// Find all games played by teams that are currently in this division,
		// or tournament games for this division
		$teams = Set::extract ('/Team/id', $division);
		$this->Division->Game->contain (array('GameSlot', 'SpiritEntry'));
		$division['Game'] = $this->Division->Game->find('all', array(
				'conditions' => array(
					'OR' => array(
						'Game.home_team' => $teams,
						'Game.away_team' => $teams,
						'AND' => array(
							'Game.division_id' => $id,
							'Game.tournament' => true,
						),
					),
				),
		));

		if (empty ($division['Game'])) {
			$this->Session->setFlash(__('Cannot generate standings for a division with no schedule.', true), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}

		// Sort games by date, time and field
		usort ($division['Game'], array ('Game', 'compareDateAndField'));
		Game::_adjustEntryIndices($division['Game']);

		$league_obj = $this->_getComponent ('LeagueType', $division['Division']['schedule_type'], $this);
		$league_obj->sort($division, false);
		$spirit_obj = $this->_getComponent ('Spirit', $division['League']['sotg_questions'], $this);

		// If we're asking for "team" standings, only show the 5 teams above and 5 teams below this team.
		// Don't bother if there are 24 teams or less (24 is probably the largest fall division size).
		// If $showall is set, don't remove teams.
		if (!$showall && $teamid != null && count($division['Team']) > 24) {
			$index_of_this_team = false;
			foreach ($division['Team'] as $i => $team) {
				if ($team['id'] == $teamid) {
					$index_of_this_team = $i;
					break;
				}
			}

			$first = $index_of_this_team - 5;
			if ($first <= 0) {
				$first = 0;
			} else {
				$more_before = $first; // need to add this to the first seed
			}
			$last = $index_of_this_team + 5;
			if ($last < count($division['Team']) - 1) {
				$more_after = true; // we never need to know how many after
			}

			$division['Team'] = array_slice ($division['Team'], $first, $last + 1 - $first);
		}
		$this->set(compact ('division', 'league_obj', 'spirit_obj', 'teamid', 'showall', 'more_before', 'more_after'));
		$this->set('is_coordinator', in_array($id, $this->Session->read('Zuluru.DivisionIDs')));

		$this->_addDivisionMenuItems ($this->Division->data['Division'], $this->Division->data['League']);
	}

	function scores() {
		$id = $this->_arg('division');
		if (!$id) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}

		$this->Division->contain(array (
			'Day' => array('order' => 'day_id'),
			'Team',
			'League',
		));
		$division = $this->Division->read(null, $id);
		if ($division === false) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}

		// Find all games played by teams that are currently in this division,
		// or tournament games for this division
		$teams = Set::extract ('/Team/id', $division);
		$this->Division->Game->contain (array(
				'HomeTeam',
				'AwayTeam',
				'GameSlot' => array('Field' => 'Facility'),
		));
		$division['Game'] = $this->Division->Game->find('all', array(
				'conditions' => array(
					'OR' => array(
						'Game.home_team' => $teams,
						'Game.away_team' => $teams,
						'AND' => array(
							'Game.division_id' => $id,
							'Game.tournament' => true,
						),
					),
				),
		));
		if (empty ($division['Game'])) {
			$this->Session->setFlash(__('This division has no games scheduled yet.', true), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}

		// Sort games by date, time and field
		usort ($division['Game'], array ('Game', 'compareDateAndField'));
		Game::_adjustEntryIndices($division['Game']);
		$league_obj = $this->_getComponent ('LeagueType', $division['Division']['schedule_type'], $this);
		$league_obj->sort($division);

		// Move the teams into an array indexed by team id, for easier use in the view
		$teams = array();
		foreach ($division['Team'] as $team) {
			$teams[$team['id']] = $team;
		}
		$division['Team'] = $teams;

		$this->set(compact ('division'));
		$this->set('is_coordinator', in_array($id, $this->Session->read('Zuluru.DivisionIDs')));

		$this->_addDivisionMenuItems ($this->Division->data['Division'], $this->Division->data['League']);
	}

	function fields() {
		$id = $this->_arg('division');
		if (!$id) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}

		if ($this->_arg('published')) {
			$conditions = array('Game.published' => true);
			$this->set('published', true);
		} else {
			$conditions = array();
		}

		$this->Division->contain(array (
			'Team' => array(
				'Field' => 'Facility',
				'Region',
			),
			'League',
			'Game' => array(
				'conditions' => $conditions,
				'GameSlot' => array('Field' => 'Facility'),
				'HomeTeam',
				'AwayTeam',
			),
		));
		$division = $this->Division->read(null, $id);
		if ($division === false) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}
		if (empty ($division['Game'])) {
			$this->Session->setFlash(__('This division has no games scheduled yet.', true), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}
		$league_obj = $this->_getComponent ('LeagueType', $division['Division']['schedule_type'], $this);
		$league_obj->sort($division);

		// Gather all possible facility/time slot combinations this division can use
		$join = array(
			array(
				'table' => "{$this->Division->tablePrefix}game_slots",
				'alias' => 'GameSlot',
				'type' => 'INNER',
				'foreignKey' => false,
				'conditions' => 'DivisionGameslotAvailability.game_slot_id = GameSlot.id',
			),
			array(
				'table' => "{$this->Division->tablePrefix}fields",
				'alias' => 'Field',
				'type' => 'LEFT',
				'foreignKey' => false,
				'conditions' => 'Field.id = GameSlot.field_id',
			),
			array(
				'table' => "{$this->Division->tablePrefix}facilities",
				'alias' => 'Facility',
				'type' => 'LEFT',
				'foreignKey' => false,
				'conditions' => 'Facility.id = Field.facility_id',
			),
			array(
				'table' => "{$this->Division->tablePrefix}regions",
				'alias' => 'Region',
				'type' => 'LEFT',
				'foreignKey' => false,
				'conditions' => 'Region.id = Facility.region_id',
			),
		);
		$facilities = $this->Division->DivisionGameslotAvailability->find('all', array(
			'fields' => array('DISTINCT Facility.id', 'Facility.code', 'Facility.name', 'Region.name',
					'GameSlot.game_start'),
			'conditions' => array('DivisionGameslotAvailability.division_id' => $id),
			'contain' => false,
			'order' => 'Region.id, Facility.code, GameSlot.game_start',
			'joins' => $join,
		));

		// Re-index the facilities array
		foreach ($facilities as $key => $facility) {
			$new_key = "{$facility['Facility']['code']} {$facility['GameSlot']['game_start']}";
			$facilities[$new_key] = $facilities[$key];
			unset($facilities[$key]);
		}

		$this->set(compact ('division', 'league_obj', 'facilities'));
		$this->set('is_coordinator', in_array($id, $this->Session->read('Zuluru.DivisionIDs')));

		$this->_addDivisionMenuItems ($this->Division->data['Division'], $this->Division->data['League']);
	}

	function _compareRegionAndCodeAndStart($a, $b) {
		if ($a['Region']['name'] < $b['Region']['name']) {
			return -1;
		} else if ($a['Region']['name'] > $b['Region']['name']) {
			return 1;
		} else if ($a['Facility']['code'] < $b['Facility']['code']) {
			return -1;
		} else if ($a['Facility']['code'] > $b['Facility']['code']) {
			return 1;
		} else if ($a['GameSlot']['game_start'] < $b['GameSlot']['game_start']) {
			return -1;
		} else if ($a['GameSlot']['game_start'] > $b['GameSlot']['game_start']) {
			return 1;
		}
		return 0;
	}

	function slots() {
		$id = $this->_arg('division');
		if (!$id) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}

		$this->Division->contain('League');
		$division = $this->Division->read(null, $id);
		if ($division === false) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}

		$this->Division->DivisionGameslotAvailability->GameSlot->recursive = -1;
		$join = array( array(
				'table' => "{$this->Division->tablePrefix}division_gameslot_availabilities",
				'alias' => 'DivisionGameslotAvailability',
				'type' => 'LEFT',
				'foreignKey' => false,
				'conditions' => 'DivisionGameslotAvailability.game_slot_id = GameSlot.id',
		));
		$dates = $this->Division->DivisionGameslotAvailability->GameSlot->find('all', array(
			'fields' => array('DISTINCT GameSlot.game_date'),
			'conditions' => array('DivisionGameslotAvailability.division_id' => $id),
			'order' => 'GameSlot.game_date',
			'joins' => $join,
		));
		$dates = Set::extract ('/GameSlot/game_date', $dates);
		$dates = array_combine (array_values ($dates), array_values ($dates));

		$date = $this->_arg('date');
		if (!empty ($this->data) && array_key_exists ('date', $this->data)) {
			$date = $this->data['date'];
		}
		if (!empty ($date)) {
			$this->Division->DivisionGameslotAvailability->GameSlot->contain (array (
					'Game' => array(
						'HomeTeam' => array(
							'Field' => 'Facility',
							'Region',
						),
						'AwayTeam' => array(
							'Field' => 'Facility',
							'Region',
						),
					),
					'Field' => array(
						'Facility' => 'Region',
					),
			));
			$slots = $this->Division->DivisionGameslotAvailability->GameSlot->find('all', array(
				'conditions' => array('DivisionGameslotAvailability.division_id' => $id, 'GameSlot.game_date' => $date),
				'joins' => $join,
			));
			$slots = Set::sort($slots, '{n}.Field.code', 'asc');
		}

		$this->set(compact('division', 'dates', 'date', 'slots'));

		$this->_addDivisionMenuItems ($this->Division->data['Division'], $this->Division->data['League']);
	}

	function status() { // TODO
		$id = $this->_arg('division');
		if (!$id) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}

	}

	function allstars() {
		$id = $this->_arg('division');
		if (!$id) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}
		$min = $this->_arg('min');
		if (!$min) {
			$min = 2;
		}

		$this->Division->contain('League');
		$division = $this->Division->read(null, $id);
		if ($division === false) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}

		$allstars = $this->Division->Game->Allstar->find ('all', array(
				'fields' => array(
					'Person.id', 'Person.first_name', 'Person.last_name', 'Person.gender', 'Person.email',
					'COUNT(Allstar.game_id) AS count',
				),
				'conditions' => array(
					'Game.division_id' => $id,
				),
				'group' => "Allstar.person_id HAVING count >= $min",
				'order' => array('Person.gender' => 'DESC', 'count' => 'DESC', 'Person.last_name', 'Person.first_name'),
		));

		$this->set(compact('division', 'allstars', 'min'));

		$this->_addDivisionMenuItems ($this->Division->data['Division'], $this->Division->data['League']);
	}

	function emails() {
		$id = $this->_arg('division');
		if (!$id) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}

		$this->Division->contain(array (
			'Team' => array (
				'Person' => array(
					'conditions' => array('TeamsPerson.position' => Configure::read('privileged_roster_positions')),
					'fields' => array('id', 'first_name', 'last_name', 'email'),
				),
			),
			'League',
		));
		$division = $this->Division->read(null, $id);
		if ($division === false) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}
		$this->set(compact('division'));

		$this->_addDivisionMenuItems ($this->Division->data['Division'], $this->Division->data['League']);
	}

	function spirit() {
		$id = $this->_arg('division');
		if (!$id) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}

		$this->Division->contain(array (
			'Game' => array(
				'GameSlot',
				'SpiritEntry',
				'HomeTeam',
				'AwayTeam',
				'order' => 'Game.id',
			),
			'League',
		));
		$division = $this->Division->read(null, $id);
		if ($division === false) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}
		if (empty ($division['Game'])) {
			$this->Session->setFlash(__('This division has no games scheduled yet.', true), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}

		$spirit_obj = $this->_getComponent ('Spirit', $division['League']['sotg_questions'], $this);

		$this->set(compact('division', 'spirit_obj'));

		$this->_addDivisionMenuItems ($this->Division->data['Division'], $this->Division->data['League']);

		// This is in case we're doing CSV output
		$this->set('download_file_name', "Spirit - {$division['Division']['full_league_name']}");
	}

	function approve_scores() {
		$id = $this->_arg('division');
		if (!$id) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}

		$this->Division->contain('League');
		$division = $this->Division->read(null, $id);
		if ($division === false) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}

		$this->Division->Game->contain (array (
			// Get the list of captains for each team, for building the email link
			'HomeTeam' => array(
				'Person' => array(
					'conditions' => array('TeamsPerson.position' => Configure::read('privileged_roster_positions')),
					'fields' => array('id', 'first_name', 'last_name', 'email'),
				),
			),
			'AwayTeam' => array(
				'Person' => array(
					'conditions' => array('TeamsPerson.position' => Configure::read('privileged_roster_positions')),
					'fields' => array('id', 'first_name', 'last_name', 'email'),
				),
			),
			'GameSlot',
			'ScoreEntry',
		));
		$games = $this->Division->Game->find ('all', array(
				'conditions' => array(
					'Game.division_id' => $id,
					'Game.approved_by' => null,
					'OR' => array(
						'GameSlot.game_date < CURDATE()',
						array(
							'GameSlot.game_date = CURDATE()',
							'GameSlot.game_end < CURTIME()',
						),
					),
				),
				'order' => array('GameSlot.game_date', 'GameSlot.game_start', 'Game.id'),
		));
		if (empty ($games)) {
			$this->Session->setFlash(__('There are currently no games to approve in this division.', true), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}
		Game::_adjustEntryIndices($games);

		$this->set(compact ('division', 'games'));
		$this->set('is_coordinator', in_array($id, $this->Session->read('Zuluru.DivisionIDs')));

		// TODO: Add this type of links everywhere. Maybe do it in beforeRender?
		$this->_addDivisionMenuItems ($this->Division->data['Division'], $this->Division->data['League']);
	}

	function initialize_ratings() {
		$id = $this->_arg('division');
		if (!$id) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}

		$this->Division->contain (array(
			'Team' => array(
				'Franchise',
			),
			'League',
		));
		$division = $this->Division->read(null, $id);
		if ($division === false) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}
		$this->Division->addPlayoffs($division);

		if (!$division['Division']['is_playoff']) {
			$this->Session->setFlash(__('Only playoff divisions can be initialized', true), 'default', array('class' => 'info'));
			$this->redirect(array('action' => 'view', 'division' => $id));
		}

		// Wrap the whole thing in a transaction, for safety.
		$transaction = new DatabaseTransaction($this->Division);

		// Initialize all teams ratings with their regular season ratings
		foreach ($division['Team'] as $key => $team) {
			$affiliate_id = $this->_getAffiliateId($division['Division'], $team);
			if ($affiliate_id === null) {
				$this->Session->setFlash($team['name'] . ' ' . __('does not have a unique affiliated team in the correct division', true), 'default', array('class' => 'warning'));
				$this->redirect(array('action' => 'view', 'division' => $id));
			}
			$this->Division->Team->contain(array('Division' => 'League'));
			$affiliate = $this->Division->Team->read(null, $affiliate_id);
			$division['Team'][$key]['rating'] = $affiliate['Team']['rating'];

			$this->Division->Team->id = $team['id'];
			if (!$this->Division->Team->saveField('rating', $affiliate['Team']['rating'])) {
				$this->Session->setFlash(__('Failed to update team rating', true), 'default', array('class' => 'warning'));
				$this->redirect(array('action' => 'view', 'division' => $id));
			}
		}

		$this->Session->setFlash(__('Team ratings have been initialized.', true), 'default', array('class' => 'success'));
		$transaction->commit();
		$this->redirect(array('action' => 'view', 'division' => $id));
	}

	function initialize_dependencies() {
		$id = $this->_arg('division');
		if (!$id) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}

		$this->Division->contain(array(
			'Team' => array(
				'Franchise',
			),
			'League',
			// We may need all of the games, as some league types use game results
			// to determine sort order.
			'Game',
		));
		$division = $this->Division->read(null, $id);
		if ($division === false) {
			$this->Session->setFlash(sprintf(__('Invalid %s', true), __('division', true)), 'default', array('class' => 'info'));
			$this->redirect(array('controller' => 'leagues', 'action' => 'index'));
		}

		$count = $this->Division->Game->find('count', array('conditions' => array(
				'Game.division_id' => $id,
				'Game.tournament' => true,
				'Game.approved_by' => null,
				'OR' => array(
					'Game.home_dependency_type' => 'seed',
					'Game.away_dependency_type' => 'seed',
				),
		)));
		if ($count == 0) {
			$this->Session->setFlash(__('There are currently no dependencies to initialize in this division.', true), 'default', array('class' => 'warning'));
			$this->redirect(array('action' => 'schedule', 'division' => $id));
		}

		$league_obj = $this->_getComponent ('LeagueType', $division['Division']['schedule_type'], $this);
		$league_obj->sort($division, false);
		$reset = $this->_arg('reset');

		// Wrap the whole thing in a transaction, for safety.
		$transaction = new DatabaseTransaction($this->Division);

		// Go through all games, updating seed dependencies
		foreach ($division['Game'] as $game) {
			$this->Division->Game->id = $game['id'];
			foreach (array('home', 'away') as $type) {
				if ($game["{$type}_dependency_type"] == 'seed') {
					if ($reset) {
						if (!$this->Division->Game->saveField("{$type}_team", null)) {
							$this->Session->setFlash(sprintf(__('Failed to %s game dependency', true), __('reset', true)), 'default', array('class' => 'warning'));
							$this->redirect(array('action' => 'schedule', 'division' => $id));
						}
					} else {
						$seed = $game["{$type}_dependency_id"];
						if ($seed > count($division['Team'])) {
							$this->Session->setFlash(__('Not enough teams in the division to fulfill all scheduled seeds', true), 'default', array('class' => 'warning'));
							$this->redirect(array('action' => 'schedule', 'division' => $id));
						}
						if (!$this->Division->Game->saveField("{$type}_team", $division['Team'][$seed - 1]['id'])) {
							$this->Session->setFlash(sprintf(__('Failed to %s game dependency', true), __('update', true)), 'default', array('class' => 'warning'));
							$this->redirect(array('action' => 'schedule', 'division' => $id));
						}
					}
				}
			}
		}
		$this->Session->setFlash(sprintf(__('Seed dependencies have been %s.', true), __($reset ? 'reset' : 'resolved', true)),
				'default', array('class' => 'success'));
		$transaction->commit();
		$this->redirect(array('action' => 'schedule', 'division' => $id));
	}

	/**
	 * Ajax functionality
	 */

	function select($date) {
		Configure::write ('debug', 0);
		$this->layout = 'ajax';
		$this->set('divisions', $this->Division->readByDate($date));
	}
}
?>