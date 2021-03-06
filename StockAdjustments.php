<?php

/* $Id$*/

include('includes/DefineStockAdjustment.php');
include('includes/DefineSerialItems.php');

include('includes/session.inc');
$title = _('Stock Adjustments');

include('includes/header.inc');
include('includes/SQL_CommonFunctions.inc');

if (empty($_GET['identifier'])) {
	/*unique session identifier to ensure that there is no conflict with other order entry sessions on the same machine  */
	$identifier=date('U');
} else {
	$identifier=$_GET['identifier'];
}

if (isset($_GET['NewAdjustment'])){
	unset($_SESSION['Adjustment'.$identifier]);
	$_SESSION['Adjustment'.$identifier] = new StockAdjustment();
}

if (!isset($_SESSION['Adjustment'.$identifier])){
	$_SESSION['Adjustment'.$identifier] = new StockAdjustment();
}

$NewAdjustment = false;

if (isset($_GET['StockID'])){
	$StockID = trim(mb_strtoupper($_GET['StockID']));
	$_SESSION['Adjustment'.$identifier]->StockID = trim(mb_strtoupper($StockID));
	$result = DB_query("SELECT description, controlled, serialised, decimalplaces FROM stockmaster WHERE stockid='" . $_SESSION['Adjustment'.$identifier]->StockID . "'",$db);
	$myrow = DB_fetch_array($result);
	$_SESSION['Adjustment'.$identifier]->ItemDescription = $myrow['description'];
	$_SESSION['Adjustment'.$identifier]->Controlled = $myrow['controlled'];
	$_SESSION['Adjustment'.$identifier]->Serialised = $myrow['serialised'];
	$_SESSION['Adjustment'.$identifier]->DecimalPlaces = $myrow['decimalplaces'];
	$_SESSION['Adjustment'.$identifier]->SerialItems = array();
	if (!isset($_SESSION['Adjustment'.$identifier]->Quantity)or !is_numeric($_SESSION['Adjustment'.$identifier]->Quantity)){
		$_SESSION['Adjustment'.$identifier]->Quantity=0;
	}
	$NewAdjustment = true;
} elseif (isset($_POST['StockID'])){
	if(isset($_POST['StockID']) and $_POST['StockID'] != $_SESSION['Adjustment'.$identifier]->StockID){
		$NewAdjustment = true;
		$_SESSION['Adjustment'.$identifier]->StockID = trim(mb_strtoupper($_POST['StockID']));
		$StockID = trim(mb_strtoupper($_POST['StockID']));
	}
	$_SESSION['Adjustment'.$identifier]->tag = $_POST['tag'];
	$_SESSION['Adjustment'.$identifier]->Narrative = $_POST['Narrative'];
	$_SESSION['Adjustment'.$identifier]->StockLocation = $_POST['StockLocation'];
	if ($_POST['Quantity']==''){
		$_POST['Quantity']=0;
	} else {
		$_POST['Quantity'] = filter_number_input($_POST['Quantity']);
	}
	$_SESSION['Adjustment'.$identifier]->Quantity = $_POST['Quantity'];
}

echo '<p class="page_title_text"><img src="'.$rootpath.'/css/'.$theme.'/images/supplier.png" title="' . _('Inventory Adjustment') . '" alt="" />' . ' ' . _('Inventory Adjustment') . '</p>';

if (isset($_POST['CheckCode'])) {

	echo '<p class="page_title_text"><img src="'.$rootpath.'/css/'.$theme.'/images/magnifier.png" title="' . _('Dispatch') . '" alt="" />' . ' ' . _('Select Item to Adjust') . '</p>';

	if (strlen($_POST['StockText'])>0) {
		$sql="SELECT stockid, description from stockmaster where description like '%".$_POST['StockText']."%'";

	} else {
		$sql="SELECT stockid, description FROM stockmaster WHERE stockid LIKE '%".$_POST['StockCode']."%'";
	}
	$ErrMsg=_('The stock information cannot be retrieved because');
	$DbgMsg=_('The SQL to get the stock description was');
	$result = DB_query($sql,$db,$ErrMsg,$DbgMsg);
	echo '<table class="selection">
			<tr>
				<th>'._('Stock Code').'</th>
				<th>'._('Stock Description').'</th>
			</tr>';
	while ($myrow = DB_fetch_array($result)) {
		echo '<tr>
				<td>'.$myrow['stockid'].'</td>
				<td>'.$myrow['description'].'</td>
				<td><a href="StockAdjustments.php?StockID='.$myrow['stockid'].'&Description='.$myrow['description'].'&identifier=' . $identifier . '">'._('Adjust').'</a></tr>';
	}
	echo '</table>';
	include('includes/footer.inc');
	exit;
}

if (isset($_POST['EnterAdjustment'])){

	$InputError = false; /*Start by hoping for the best */
	$result = DB_query("SELECT stockid FROM stockmaster WHERE stockid='" . $_SESSION['Adjustment'.$identifier]->StockID . "'",$db);
	if (DB_num_rows($result)==0) {
		prnMsg( _('The entered item code does not exist'),'error');
		$InputError = true;
	} elseif (!is_numeric($_SESSION['Adjustment'.$identifier]->Quantity)){
		prnMsg( _('The quantity entered must be numeric'),'error');
		$InputError = true;
	} elseif ($_SESSION['Adjustment'.$identifier]->Quantity==0){
		prnMsg( _('The quantity entered cannot be zero') . '. ' . _('There would be no adjustment to make'),'error');
		$InputError = true;
	} elseif ($_SESSION['Adjustment'.$identifier]->Controlled==1 AND count($_SESSION['Adjustment'.$identifier]->SerialItems)==0) {
		prnMsg( _('The item entered is a controlled item that requires the detail of the serial numbers or batch references to be adjusted to be entered'),'error');
		$InputError = true;
	}

	if ($_SESSION['ProhibitNegativeStock']==1){
		$SQL = "SELECT quantity FROM locstock
				WHERE stockid='" . $_SESSION['Adjustment'.$identifier]->StockID . "'
				AND loccode='" . $_SESSION['Adjustment'.$identifier]->StockLocation . "'";
		$CheckNegResult=DB_query($SQL,$db);
		$CheckNegRow = DB_fetch_array($CheckNegResult);
		if ($CheckNegRow['quantity']+$_SESSION['Adjustment'.$identifier]->Quantity <0){
			$InputError=true;
			prnMsg(_('The system parameters are set to prohibit negative stocks. Processing this stock adjustment would result in negative stock at this location. This adjustment will not be processed.'),'error');
		}
	}

	if (!$InputError) {

/*All inputs must be sensible so make the stock movement records and update the locations stocks */

		$AdjustmentNumber = GetNextTransNo(17,$db);
		$PeriodNo = GetPeriod (Date($_SESSION['DefaultDateFormat']), $db);
		$SQLAdjustmentDate = FormatDateForSQL(Date($_SESSION['DefaultDateFormat']));

		$Result = DB_Txn_Begin($db);

		// Need to get the current location quantity will need it later for the stock movement
		$SQL="SELECT locstock.quantity
			FROM locstock
			WHERE locstock.stockid='" . $_SESSION['Adjustment'.$identifier]->StockID . "'
			AND loccode= '" . $_SESSION['Adjustment'.$identifier]->StockLocation . "'";
		$Result = DB_query($SQL, $db);
		if (DB_num_rows($Result)==1){
			$LocQtyRow = DB_fetch_array($Result);
			$QtyOnHandPrior = $LocQtyRow['quantity'];
		} else {
			// There must actually be some error this should never happen
			$QtyOnHandPrior = 0;
		}

		$SQL = "INSERT INTO stockmoves (
				stockid,
				type,
				transno,
				loccode,
				trandate,
				prd,
				reference,
				qty,
				newqoh)
			VALUES (
				'" . $_SESSION['Adjustment'.$identifier]->StockID . "',
				17,
				'" . $AdjustmentNumber . "',
				'" . $_SESSION['Adjustment'.$identifier]->StockLocation . "',
				'" . $SQLAdjustmentDate . "',
				'" . $PeriodNo . "',
				'" . $_SESSION['Adjustment'.$identifier]->Narrative ."',
				'" . $_SESSION['Adjustment'.$identifier]->Quantity . "',
				'" . ($QtyOnHandPrior + $_SESSION['Adjustment'.$identifier]->Quantity) . "'
			)";


		$ErrMsg =  _('CRITICAL ERROR') . '! ' . _('NOTE DOWN THIS ERROR AND SEEK ASSISTANCE') . ': ' . _('The stock movement record cannot be inserted because');
		$DbgMsg =  _('The following SQL to insert the stock movement record was used');
		$Result = DB_query($SQL, $db, $ErrMsg, $DbgMsg, true);


/*Get the ID of the StockMove... */
		$StkMoveNo = DB_Last_Insert_ID($db,'stockmoves','stkmoveno');

/*Insert the StockSerialMovements and update the StockSerialItems  for controlled items*/

		if ($_SESSION['Adjustment'.$identifier]->Controlled ==1){
			foreach($_SESSION['Adjustment'.$identifier]->SerialItems as $Item){
			/*We need to add or update the StockSerialItem record and
			The StockSerialMoves as well */

				/*First need to check if the serial items already exists or not */
				$SQL = "SELECT stockid
					FROM stockserialitems
					WHERE
					stockid='" . $_SESSION['Adjustment'.$identifier]->StockID . "'
					AND loccode='" . $_SESSION['Adjustment'.$identifier]->StockLocation . "'
					AND serialno='" . $Item->BundleRef . "'";
				$ErrMsg = _('Unable to determine if the serial item exists');
				$Result = DB_query($SQL,$db,$ErrMsg);

				if (DB_num_rows($Result)==1){

					$SQL = "UPDATE stockserialitems SET
						quantity= quantity + " . $Item->BundleQty . "
						WHERE
						stockid='" . $_SESSION['Adjustment'.$identifier]->StockID . "'
						AND loccode='" . $_SESSION['Adjustment'.$identifier]->StockLocation . "'
						AND serialno='" . $Item->BundleRef . "'";

					$ErrMsg =  _('CRITICAL ERROR') . '! ' . _('NOTE DOWN THIS ERROR AND SEEK ASSISTANCE') . ': ' . _('The serial stock item record could not be updated because');
					$DbgMsg =  _('The following SQL to update the serial stock item record was used');
					$Result = DB_query($SQL, $db, $ErrMsg, $DbgMsg, true);
				} else {
					/*Need to insert a new serial item record */
					$SQL = "INSERT INTO stockserialitems (stockid,
									loccode,
									serialno,
									qualitytext,
									quantity)
						VALUES ('" . $_SESSION['Adjustment'.$identifier]->StockID . "',
						'" . $_SESSION['Adjustment'.$identifier]->StockLocation . "',
						'" . $Item->BundleRef . "',
						'',
						'" . $Item->BundleQty . "'
						)";

					$ErrMsg =  _('CRITICAL ERROR') . '! ' . _('NOTE DOWN THIS ERROR AND SEEK ASSISTANCE') . ': ' . _('The serial stock item record could not be updated because');
					$DbgMsg =  _('The following SQL to update the serial stock item record was used');
					$Result = DB_query($SQL, $db, $ErrMsg, $DbgMsg, true);
				}


				/* now insert the serial stock movement */

				$SQL = "INSERT INTO stockserialmoves (stockmoveno,
									stockid,
									serialno,
									moveqty)
						VALUES ('" . $StkMoveNo . "',
							'" . $_SESSION['Adjustment'.$identifier]->StockID . "',
							'" . $Item->BundleRef . "',
							'" . $Item->BundleQty . "')";
				$ErrMsg =  _('CRITICAL ERROR') . '! ' . _('NOTE DOWN THIS ERROR AND SEEK ASSISTANCE') . ': ' . _('The serial stock movement record could not be inserted because');
				$DbgMsg =  _('The following SQL to insert the serial stock movement records was used');
				$Result = DB_query($SQL, $db, $ErrMsg, $DbgMsg, true);

			}/* foreach controlled item in the serialitems array */
		} /*end if the adjustment item is a controlled item */



		$SQL = "UPDATE locstock SET quantity = quantity + '" . $_SESSION['Adjustment'.$identifier]->Quantity . "'
				WHERE stockid='" . $_SESSION['Adjustment'.$identifier]->StockID . "'
				AND loccode='" . $_SESSION['Adjustment'.$identifier]->StockLocation . "'";

		$ErrMsg = _('CRITICAL ERROR') . '! ' . _('NOTE DOWN THIS ERROR AND SEEK ASSISTANCE') . ': ' ._('The location stock record could not be updated because');
		$DbgMsg = _('The following SQL to update the stock record was used');

		$Result = DB_query($SQL, $db, $ErrMsg, $DbgMsg, true);

		if ($_SESSION['CompanyRecord']['gllink_stock']==1 AND $_SESSION['Adjustment'.$identifier]->StandardCost > 0){

			$StockGLCodes = GetStockGLCode($_SESSION['Adjustment'.$identifier]->StockID,$db);

			$SQL = "INSERT INTO gltrans (type,
							typeno,
							trandate,
							periodno,
							account,
							defaulttag,
							amount,
							narrative,
							tag)
					VALUES (17,
						'" .$AdjustmentNumber . "',
						'" . $SQLAdjustmentDate . "',
						'" . $PeriodNo . "',
						'" .  $StockGLCodes['adjglact'] . "',
						'" . $_SESSION['DefaultTag'] . "',
						'" . $_SESSION['Adjustment'.$identifier]->StandardCost * -($_SESSION['Adjustment'.$identifier]->Quantity) . "',
						'" . $_SESSION['Adjustment'.$identifier]->StockID . " x " . $_SESSION['Adjustment'.$identifier]->Quantity . " @ " .
							$_SESSION['Adjustment'.$identifier]->StandardCost . " " . $_SESSION['Adjustment'.$identifier]->Narrative . "',
						'" . $_SESSION['Adjustment'.$identifier]->tag . "'
						)";

			$ErrMsg = _('CRITICAL ERROR') . '! ' . _('NOTE DOWN THIS ERROR AND SEEK ASSISTANCE') . ': ' . _('The general ledger transaction entries could not be added because');
			$DbgMsg = _('The following SQL to insert the GL entries was used');
			$Result = DB_query($SQL,$db, $ErrMsg, $DbgMsg, true);

			$SQL = "INSERT INTO gltrans (type,
							typeno,
							trandate,
							periodno,
							account,
							defaulttag,
							amount,
							narrative,
							tag)
					VALUES (17,
						'" .$AdjustmentNumber . "',
						'" . $SQLAdjustmentDate . "',
						'" . $PeriodNo . "',
						'" .  $StockGLCodes['stockact'] . "',
						'" . $_SESSION['DefaultTag'] . "',
						'" . $_SESSION['Adjustment'.$identifier]->StandardCost * $_SESSION['Adjustment'.$identifier]->Quantity . "',
						'" . $_SESSION['Adjustment'.$identifier]->StockID . " x " . $_SESSION['Adjustment'.$identifier]->Quantity . " @ " . $_SESSION['Adjustment'.$identifier]->StandardCost . " " . $_SESSION['Adjustment'.$identifier]->Narrative . "',
						'" . $_SESSION['Adjustment'.$identifier]->tag . "'
						)";

			$Errmsg = _('CRITICAL ERROR') . '! ' . _('NOTE DOWN THIS ERROR AND SEEK ASSISTANCE') . ': ' . _('The general ledger transaction entries could not be added because');
			$DbgMsg = _('The following SQL to insert the GL entries was used');
			$Result = DB_query($SQL,$db, $ErrMsg, $DbgMsg,true);
		}

		$Result = DB_Txn_Commit($db);

		$ConfirmationText = _('A stock adjustment for'). ' ' . $_SESSION['Adjustment'.$identifier]->StockID . ' -  ' . $_SESSION['Adjustment'.$identifier]->ItemDescription . ' '._('has been created from location').' ' . $_SESSION['Adjustment'.$identifier]->StockLocation .' '. _('for a quantity of') . ' ' . $_SESSION['Adjustment'.$identifier]->Quantity ;
		prnMsg( $ConfirmationText,'success');

		if ($_SESSION['InventoryManagerEmail']!=''){
			$ConfirmationText = $ConfirmationText . ' ' . _('by user') . ' ' . $_SESSION['UserID'] . ' ' . _('at') . ' ' . Date('Y-m-d H:i:s');
			$EmailSubject = _('Stock adjustment for'). ' ' . $_SESSION['Adjustment'.$identifier]->StockID;
			mail($_SESSION['InventoryManagerEmail'],$EmailSubject,$ConfirmationText);
		}
		unset ($_SESSION['Adjustment'.$identifier]);
	} /* end if there was no input error */

}/* end if the user hit enter the adjustment */


echo '<form action="'. htmlspecialchars($_SERVER['PHP_SELF'] . '?identifier=' . $identifier, ENT_QUOTES, 'UTF-8') . '" method="post">';
echo '<input type="hidden" name="FormID" value="' . $_SESSION['FormID'] . '" />';

if (!isset($_SESSION['Adjustment'.$identifier])) {
	$StockID='';
	$Controlled= 0;
	$Quantity = 0;
} else {
	$StockID = $_SESSION['Adjustment'.$identifier]->StockID;
	$Controlled = $_SESSION['Adjustment'.$identifier]->Controlled;
	$Quantity = $_SESSION['Adjustment'.$identifier]->Quantity;
	$sql="SELECT materialcost, labourcost, overheadcost, units FROM stockmaster WHERE stockid='".$StockID."'";
	$result=DB_query($sql, $db);
	$myrow=DB_fetch_array($result);
	$_SESSION['Adjustment'.$identifier]->PartUnit=$myrow['units'];
	$_SESSION['Adjustment'.$identifier]->StandardCost=$myrow['materialcost']+$myrow['labourcost']+$myrow['overheadcost'];
}
echo '<br /><table class="selection">';
echo '<tr><th colspan="4" class="header">'._('Adjustment Details').'</th></tr>';
if (!isset($_GET['Description'])) {
	$_GET['Description']='';
}
echo '<tr><td>'. _('Stock Code'). ':</td><td>';
if (isset($StockID)) {
	echo '<input type="text" name="StockID" size="21" value="' . $StockID . '" maxlength="20" /></td></tr>';
} else {
	echo '<input type="text" name="StockID" size="21" value="" maxlength="20" /></td></tr>';
}
echo '<tr>
		<td>'. _('Partial Description'). ':</td>
		<td><input type="text" name="StockText" size="21" value="' . $_GET['Description'] .'" />&nbsp; &nbsp;'._('Partial Stock Code'). ':</td>
		<td>';
if (isset($StockID)) {
	echo '<input type="text" name="StockCode" size="21" value="' . $StockID .'" maxlength="20" />';
} else {
	echo '<input type="text" name="StockCode" size="21" value="" maxlength="20" />';
}
echo '</td><td><button type="submit" name="CheckCode">'._('Check Part').'</button></td></tr>';
if (isset($_SESSION['Adjustment'.$identifier]) and mb_strlen($_SESSION['Adjustment'.$identifier]->ItemDescription)>1){
	echo '<tr><td colspan="3"><font size="3">' . $_SESSION['Adjustment'.$identifier]->ItemDescription . ' ('._('In Units of').' ' .
		$_SESSION['Adjustment'.$identifier]->PartUnit . ' ) - ' . _('Unit Cost').' = ' .
			locale_money_format($_SESSION['Adjustment'.$identifier]->StandardCost,$_SESSION['CompanyRecord']['currencydefault']) . '</font></td></tr>';
}

echo '<tr><td>'. _('Adjustment to Stock At Location').':</td><td><select name="StockLocation"> ';

$sql = "SELECT loccode, locationname FROM locations";
$resultStkLocs = DB_query($sql,$db);
while ($myrow=DB_fetch_array($resultStkLocs)){
	if (isset($_SESSION['Adjustment'.$identifier]->StockLocation)){
		if ($myrow['loccode'] == $_SESSION['Adjustment'.$identifier]->StockLocation){
			 echo '<option selected="True" value="' . $myrow['loccode'] . '">' . $myrow['locationname'] . '</option>';
		} else {
			 echo '<option value="' . $myrow['loccode'] . '">' . $myrow['locationname'] . '</option>';
		}
	} elseif ($myrow['loccode']==$_SESSION['UserStockLocation']){
		 echo '<option selected="True" value="' . $myrow['loccode'] . '">' . $myrow['locationname'] . '</option>';
		 $_POST['StockLocation']=$myrow['loccode'];
	} else {
		 echo '<option value="' . $myrow['loccode'] . '">' . $myrow['locationname'] . '</option>';
	}
}

echo '</select></td></tr>';
if (isset($_SESSION['Adjustment'.$identifier]) and !isset($_SESSION['Adjustment'.$identifier]->Narrative)) {
	$_SESSION['Adjustment'.$identifier]->Narrative = '';
}

echo '<tr><td>'. _('Comments On Why').':</td>
	<td><input type="text" name="Narrative" size="32" maxlength="30" value="' . $_SESSION['Adjustment'.$identifier]->Narrative . '" /></td></tr>';

echo '<tr><td>'._('Adjustment Quantity').':</td>';

echo '<td>';
if ($Controlled==1){
		if ($_SESSION['Adjustment'.$identifier]->StockLocation == ''){
			$_SESSION['Adjustment'.$identifier]->StockLocation = $_SESSION['UserStockLocation'];
		}
		echo '<input type="hidden" name="Quantity" value="' . locale_number_format($_SESSION['Adjustment'.$identifier]->Quantity , $_SESSION['Adjustment'.$identifier]->DecimalPlaces). '" />
				'.locale_number_format($_SESSION['Adjustment'.$identifier]->Quantity, $_SESSION['Adjustment'.$identifier]->DecimalPlaces).' &nbsp; &nbsp; &nbsp; &nbsp;
				[<a href="'.$rootpath.'/StockAdjustmentsControlled.php?AdjType=REMOVE&identifier=' . $identifier . '">'._('Remove').'</a>]
				[<a href="'.$rootpath.'/StockAdjustmentsControlled.php?AdjType=ADD&identifier=' . $identifier . '">'._('Add').'</a>]';
} else {
	echo '<input type="text" class="number" name="Quantity" size="12" maxlength="12" value="' . locale_number_format($Quantity, $_SESSION['Adjustment'.$identifier]->DecimalPlaces) . '" />';
}
echo '</td></tr>';
	//Select the tag
echo '<tr><td>'._('Select Tag').'</td><td><select name="tag">';

$SQL = "SELECT tagref,
				tagdescription
		FROM tags
		ORDER BY tagref";

$result=DB_query($SQL,$db);
echo '<option value=0>0 - None</option>';
while ($myrow=DB_fetch_array($result)){
	if (isset($_SESSION['Adjustment'.$identifier]->tag) and $_SESSION['Adjustment'.$identifier]->tag==$myrow['tagref']){
		echo '<option selected="True" value="' . $myrow['tagref'] . '">' . $myrow['tagref'].' - ' .$myrow['tagdescription'] . '</option>';
	} else {
		echo '<option value="' . $myrow['tagref'] . '">' . $myrow['tagref'].' - ' .$myrow['tagdescription'] . '</option>';
	}
}
echo '</select></td>';
// End select tag

echo '</table><div class="centre"><br /><button type="submit" name="EnterAdjustment">'. _('Enter Stock Adjustment'). '</button><br />';

if (!isset($_POST['StockLocation'])) {
	$_POST['StockLocation']='';
}

echo '<br /><a href="'. $rootpath. '/StockStatus.php?StockID='. $StockID . '">'._('Show Stock Status').'</a>';
echo '<br /><a href="'.$rootpath.'/StockMovements.php?StockID=' . $StockID . '">'._('Show Movements').'</a>';
echo '<br /><a href="'.$rootpath.'/StockUsage.php?StockID=' . $StockID . '&StockLocation=' . $_POST['StockLocation'] . '">'._('Show Stock Usage').'</a>';
echo '<br /><a href="'.$rootpath.'/SelectSalesOrder.php?SelectedStockItem='. $StockID .'&StockLocation=' . $_POST['StockLocation'] . '">'. _('Search Outstanding Sales Orders').'</a>';
echo '<br /><a href="'.$rootpath.'/SelectCompletedOrder.php?SelectedStockItem=' . $StockID .'">'._('Search Completed Sales Orders').'</a>';

echo '</div></form>';
include('includes/footer.inc');
?>