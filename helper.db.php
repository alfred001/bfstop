<?php
defined('_JEXEC') or die;

class BFStopDBHelper {

	private $db;
	private $logger;

	function getClientString($id)
	{
		return ($id == 0) ? 'Frontend': 'Backend';
	}

	public function __construct($logger)
	{
		$this->db = JFactory::getDbo();
		$this->logger = $logger;
	}

	public function checkDBError()
	{
		$errNum = $this->db->getErrorNum();
		if ($errNum != 0)
		{
			$errMsg = $this->db->getErrorMsg();
			$this->logger->log("Database error (#$errNum) occured: $errMsg", JLog::EMERGENCY);
		}
	}

	public function eventsInInterval(
		$interval,
		$time,
		$additionalWhere,
		$table='#__bfstop_failedlogin',
		$timecol='logtime')
	{
		// check if in the last $interval hours, $number incidents have occured already:
		$sql = "SELECT COUNT(*) FROM ".$table." t ".
			"WHERE t.".$timecol.
			" between DATE_SUB(".
			$this->db->quote($time).
			", INTERVAL $interval MINUTE) AND ".
			$this->db->quote($time).
			" ".$additionalWhere;
		$this->db->setQuery($sql);
		$numberOfEvents = ((int)$this->db->loadResult());
		$this->checkDBError();
		return $numberOfEvents;
	}

	public function getNumberOfFailedLogins($interval, $ipaddress, $logtime)
	{
		return $this->eventsInInterval($interval, $logtime,
			'AND ipaddress = '.$this->db->quote($ipaddress).
			' AND handled = 0',
			'#__bfstop_failedlogin',
			'logtime');
	}

	public function getFormattedFailedList($ipAddress, $curTime, $interval)
	{
		$sql = "SELECT * FROM #__bfstop_failedlogin t where ipaddress=".
			$this->db->quote($ipAddress).
			" AND t.logtime".
			" between DATE_SUB(".$this->db->quote($curTime).
			", INTERVAL $interval MINUTE) AND ".
			$this->db->quote($curTime);
		$this->db->setQuery($sql);
		$entries = $this->db->loadObjectList();
		$this->checkDBError();
		$result = str_pad(JText::_('USERNAME'), 25)." ".
				str_pad(JText::_('IPADDRESS') , 15)." ".
				str_pad(JText::_('DATETIME')  , 20)." ".
				str_pad(JText::_('ORIGIN')    ,  8)."\n".
				str_repeat("-", 97)."\n";
		foreach ($entries as $entry)
		{
			$result .= str_pad($entry->username               , 25)." ".
				str_pad($entry->ipaddress                     , 15)." ".
				str_pad($entry->logtime                       , 20)." ".
				str_pad($this->getClientString($entry->origin),  8)."\n";
		}
		return $result;
	}

	public function isIPBlocked($ipaddress, $blockDuration)
	{
		$sqlCheck = "SELECT COUNT(*) from #__bfstop_bannedip b WHERE ipaddress=".
			$this->db->quote($ipaddress);
		if ($blockDuration != 0)
		{
			$sqlCheck .= " AND DATE_ADD(crdate, INTERVAL $blockDuration MINUTE) >= '".
				date("Y-m-d H:i:s")."'";
		}
		$sqlCheck .= " AND NOT EXISTS (SELECT 1 FROM #__bfstop_unblock u WHERE b.id = u.block_id)";
		$this->db->setQuery($sqlCheck);
		$numRows = $this->db->loadResult();
		$this->checkDBError();
		return ($numRows > 0);
	}

	public function blockIP($logEntry)
	{
		$blockEntry = new stdClass();
		$blockEntry->ipaddress = $logEntry->ipaddress;
		$blockEntry->crdate = date("Y-m-d H:i:s");
		if (!$this->db->insertObject('#__bfstop_bannedip', $blockEntry, 'id'))
		{
			$this->logger->log('Insert block entry failed!', JLog::WARNING);
			$blockEntry->id = -1;
		}
		$this->checkDBError();
		$this->setFailedLoginHandled($logEntry);
		return $blockEntry->id;
	}

	public function getNewUnblockToken($id)
	{
		$strongCrypto = false;
		$tokenEntry = new stdClass();
		$tokenEntry->token = sha1(openssl_random_pseudo_bytes(64, $strongCrypto));
		if (!$strongCrypto)
		{
			$this->logger->log('Your server does not use strong cryptographics to produce tokens!', JLog::WARNING);
		}
		$tokenEntry->block_id = $id;
		$tokenEntry->crdate = date("Y-m-d H:i:s");
		if (!$this->db->insertObject('#__bfstop_unblock_token', $tokenEntry))
		{
			// maybe check if duplicate token (=PRIMARY KEY violation) and retry?
			$this->logger->log('Insert unblock token failed!', JLog::WARNING);
			$tokenEntry->token = null;
		}
		$this->checkDBError();
		return $tokenEntry->token;
	}

	public function unblockTokenExists($token)
	{
		$sql = "SELECT token FROM #__bfstop_unblock_token WHERE token=".
			$this->db->quote($token);
		$this->db->setQuery($sql);
		$result = $this->db->loadResult();
		$this->checkDBError();
		return $result != null;
	}

	private function getUserEmailWhere($where)
	{
		$sql = "select email from #__users where $where LIMIT 1";
		$this->db->setQuery($sql);
		$emailAddress = $this->db->loadResult();
		$this->checkDBError();
		return $emailAddress;
	}

	public function getUserEmailByID($uid)
	{
		return $this->getUserEmailWhere("id='$uid'");
	}

	public function insertFailedLogin($logEntry)
	{
		$logQuery = $this->db->insertObject('#__bfstop_failedlogin', $logEntry, 'id');
		$this->checkDBError();
	}

	public function setFailedLoginHandled($info)
	{
		$sql = 'UPDATE #__bfstop_failedlogin SET handled=1'.
			' WHERE username='.$this->db->quote($info->username).
			' AND ipaddress='.$this->db->quote($info->ipaddress).
			' AND handled=0';
		$this->db->setQuery($sql);
		$this->db->query();
		$this->checkDBError();
	}

	public function successfulLogin($info)
	{
		$this->setFailedLoginHandled($info);
	}

	public function getUserEmailByName($username)
	{
		return $this->getUserEmailWhere("username='$username'");
	}
}
