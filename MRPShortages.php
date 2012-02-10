<?php

/*$Id: MRPShortages.php 4578 2011-05-28 11:01:00Z daintree $ */
// MRPShortages.php - Report of parts with demand greater than supply as determined by MRP

include('includes/session.inc');

//ANSI SQL???
$sql="SHOW TABLES WHERE Tables_in_" . $_SESSION['DatabaseName'] . "='mrprequirements'";

$result=DB_query($sql,$db);
if (DB_num_rows($result)==0) {
	$title=_('MRP error');
	include('includes/header.inc');
	echo '<br />';
	prnMsg( _('The MRP calculation must be run before you can run this report').'<br />'.
			_('To run the MRP calculation click').' '.'<a href="'.$rootpath .'/MRP.php">'._('here').'</a>', 'error');
	include('includes/footer.inc');
	exit;
}

if (isset($_POST['PrintPDF'])) {

	include('includes/PDFStarter.php');
	if ($_POST['ReportType'] == 'Shortage'){
		$pdf->addInfo('Title',_('MRP Shortages Report'));
		$pdf->addInfo('Subject',_('MRP Shortages'));
	}else{
		$pdf->addInfo('Title',_('MRP Excess Report'));
		$pdf->addInfo('Subject',_('MRP Excess'));
	}
	$FontSize=9;
	$PageNumber=1;
	$line_height=12;

// Create temporary tables for supply and demand, with one record per part with the
// total for either supply or demand. Did this to simplify main sql where used
// several subqueries.

	$sql = "CREATE TEMPORARY TABLE demandtotal (
				part char(20),
				demand double,
				KEY `PART` (`part`)) DEFAULT CHARSET=utf8";
	$result = DB_query($sql,$db,_('Create of demandtotal failed because'));

	$sql = "INSERT INTO demandtotal
						(part,
						 demand)
			   SELECT part,
					  SUM(quantity) as demand
				FROM mrprequirements
				GROUP BY part";
	$result = DB_query($sql,$db);

	$sql = "CREATE TEMPORARY TABLE supplytotal (
				part char(20),
				supply double,
				KEY `PART` (`part`)) DEFAULT CHARSET=utf8";
	$result = DB_query($sql,$db,_('Create of supplytotal failed because'));

/* 21/03/2010: Ricard modification to allow items with total supply = 0 be included in the report */

	$sql = "INSERT INTO supplytotal
						(part,
						 supply)
			SELECT stockid,
				  0
			FROM stockmaster";
	$result = DB_query($sql,$db);

	$sql = "UPDATE supplytotal
			SET supply = (SELECT SUM(mrpsupplies.supplyquantity)
							FROM mrpsupplies
							WHERE supplytotal.part = mrpsupplies.part
								AND mrpsupplies.supplyquantity > 0)";
	$result = DB_query($sql,$db);

	$sql = "UPDATE supplytotal SET supply = 0 WHERE supply IS NULL";
	$result = DB_query($sql,$db);


	// Only include directdemand mrprequirements so don't have demand for top level parts and also
	// show demand for the lower level parts that the upper level part generates. See MRP.php for
	// more notes - Decided not to exclude derived demand so using $sql, not $sqlexclude
	$sqlexclude = "SELECT stockmaster.stockid,
					   stockmaster.description,
					   stockmaster.mbflag,
					   stockmaster.actualcost,
					   stockmaster.decimalplaces,
					   (stockmaster.materialcost + stockmaster.labourcost +
						stockmaster.overheadcost ) as computedcost,
					   demandtotal.demand,
					   supplytotal.supply,
					   (demandtotal.demand - supplytotal.supply) *
					   (stockmaster.materialcost + stockmaster.labourcost +
						stockmaster.overheadcost ) as extcost
					FROM stockmaster
						LEFT JOIN demandtotal ON stockmaster.stockid = demandtotal.part
						LEFT JOIN supplytotal ON stockmaster.stockid = supplytotal.part
					  GROUP BY stockmaster.stockid,
							   stockmaster.description,
							   stockmaster.mbflag,
							   stockmaster.actualcost,
							   stockmaster.decimalplaces,
							   supplytotal.supply,
							   demandtotal.demand,
							   extcost
					  HAVING demand > supply
					  ORDER BY '" . $_POST['Sort']."'";

	  if ($_POST['CategoryID'] == 'All'){
		$SQLCategory = ' ';
	  }else{
		$SQLCategory = "WHERE stockmaster.categoryid = '" . $_POST['CategoryID'] . "'";
	  }

	  if ($_POST['ReportType'] == 'Shortage'){
		$SQLHaving = " HAVING demandtotal.demand > supplytotal.supply ";
	  }else{
		$SQLHaving = " HAVING demandtotal.demand <= supplytotal.supply ";
	  }

	  $sql = "SELECT stockmaster.stockid,
		stockmaster.description,
		stockmaster.mbflag,
		stockmaster.actualcost,
		stockmaster.decimalplaces,
		(stockmaster.materialcost + stockmaster.labourcost +
		 stockmaster.overheadcost ) as computedcost,
		demandtotal.demand,
		supplytotal.supply,
	   (demandtotal.demand - supplytotal.supply) *
	   (stockmaster.materialcost + stockmaster.labourcost +
		stockmaster.overheadcost ) as extcost
		   FROM stockmaster
			 LEFT JOIN demandtotal ON stockmaster.stockid = demandtotal.part
			 LEFT JOIN supplytotal ON stockmaster.stockid = supplytotal.part "
			 . $SQLCategory .
			 "GROUP BY stockmaster.stockid,
			   stockmaster.description,
			   stockmaster.mbflag,
			   stockmaster.actualcost,
			   stockmaster.decimalplaces,
			   stockmaster.materialcost,
			   stockmaster.labourcost,
			   stockmaster.overheadcost,
			   computedcost,
			   supplytotal.supply,
			   demandtotal.demand "
			   . $SQLHaving .
			   " ORDER BY '" . $_POST['Sort'] . "'";
	$result = DB_query($sql,$db,'','',false,true);

	if (DB_error_no($db) !=0) {
	  $title = _('MRP Shortages and Excesses') . ' - ' . _('Problem Report');
	  include('includes/header.inc');
	   prnMsg( _('The MRP shortages and excesses could not be retrieved by the SQL because') . ' '  . DB_error_msg($db),'error');
	   echo '<br /><a href="' .$rootpath .'/index.php">' . _('Back to the menu') . '</a>';
	   if ($debug==1){
		  echo '<br />' . $sql;
	   }
	   include('includes/footer.inc');
	   exit;
	}

	if (DB_num_rows($result) == 0) {
	  $title = _('MRP Shortages and Excesses') . ' - ' . _('Problem Report');
	  include('includes/header.inc');
	   prnMsg( _('No MRP shortages - Excess retrieved'), 'warn');
	   echo '<br /><a href="' .$rootpath .'/index.php">' . _('Back to the menu') . '</a>';
	   if ($debug==1){
		  echo '<br />' . $sql;
	   }
	   include('includes/footer.inc');
	   exit;
	}

	PrintHeader($pdf,$YPos,$PageNumber,$Page_Height,$Top_Margin,$Left_Margin,$Page_Width,
					   $Right_Margin);

	$Total_Shortage=0;
	$Partctr = 0;
	$fill = false;
	$pdf->SetFillColor(224,235,255);  // Defines color to make alternating lines highlighted
	while ($myrow = DB_fetch_array($result,$db)){

		if ($_POST['ReportType'] == 'Shortage'){
			$lineToPrint = ($myrow['demand'] > $myrow['supply']);
		}else{
			$lineToPrint = ($myrow['demand'] <= $myrow['supply']);
		}

		if ($lineToPrint) {
			$YPos -=$line_height;
			$FontSize=8;

			// Use to alternate between lines with transparent and painted background
			if ($_POST['Fill'] == 'yes'){
				$fill=!$fill;
			}

			// Parameters for addTextWrap are defined in /includes/class.pdf.php
			// 1) X position 2) Y position 3) Width
			// 4) Height 5) text 6) Alignment 7) Border 8) Fill - True to use SetFillColor
			// and False to set to transparent
			$shortage = ($myrow['demand'] - $myrow['supply']) * -1;
			$extcost = $shortage * $myrow['computedcost'];
			$pdf->addTextWrap($Left_Margin,$YPos,90,$FontSize,$myrow['stockid'],'',0,$fill);
			$pdf->addTextWrap(130,$YPos,150,$FontSize,$myrow['description'],'',0,$fill);
			$pdf->addTextWrap(280,$YPos,25,$FontSize,$myrow['mbflag'],'right',0,$fill);
			$pdf->addTextWrap(305,$YPos,55,$FontSize,locale_money_format($myrow['computedcost'],$_SESSION['CompanyRecord']['currencydefault']),'right',0,$fill);
			$pdf->addTextWrap(360,$YPos,50,$FontSize,locale_number_format($myrow['supply'], $myrow['decimalplaces']),'right',0,$fill);
			$pdf->addTextWrap(410,$YPos,50,$FontSize,locale_number_format($myrow['demand'], $myrow['decimalplaces']),'right',0,$fill);
			$pdf->addTextWrap(460,$YPos,50,$FontSize,locale_number_format($shortage, $myrow['decimalplaces']),'right',0,$fill);
			$pdf->addTextWrap(510,$YPos,60,$FontSize,locale_money_format($myrow['extcost'],$_SESSION['CompanyRecord']['currencydefault']),'right',0,$fill);

			$Total_Shortage += $myrow['extcost'];
			$Partctr++;

			if ($YPos < $Bottom_Margin + $line_height){
			   PrintHeader($pdf,$YPos,$PageNumber,$Page_Height,$Top_Margin,$Left_Margin,$Page_Width,
					   $Right_Margin);
			}
		}

	} /*end while loop */

	$FontSize =8;
	$YPos -= (2*$line_height);

	if ($YPos < $Bottom_Margin + $line_height){
		   PrintHeader($pdf,$YPos,$PageNumber,$Page_Height,$Top_Margin,$Left_Margin,$Page_Width,
					   $Right_Margin);
	}
/*Print out the grand totals */
	$pdf->addTextWrap($Left_Margin,$YPos,120,$FontSize,_('Number of Parts: '), 'left');
	$pdf->addTextWrap(150,$YPos,30,$FontSize,$Partctr, 'left');
	if ($_POST['ReportType'] == 'Shortage'){
	$pdf->addTextWrap(300,$YPos,180,$FontSize,_('Total Extended Shortage:'), 'right');
	}else{
		$pdf->addTextWrap(300,$YPos,180,$FontSize,_('Total Extended Excess:'), 'right');
	}
	$DisplayTotalVal = locale_money_format($Total_Shortage,$_SESSION['CompanyRecord']['currencydefault']);
	$pdf->addTextWrap(510,$YPos,60,$FontSize,$DisplayTotalVal, 'right');

	if ($_POST['ReportType'] == 'Shortage'){
		$pdf->OutputD($_SESSION['DatabaseName'] . '_MRPShortages_' . date('Y-m-d').'.pdf');
	}else{
		$pdf->OutputD($_SESSION['DatabaseName'] . '_MRPExcess_' . date('Y-m-d').'.pdf');
	}
	$pdf->__destruct();
} else { /*The option to print PDF was not hit so display form */

	$title=_('MRP Shortages - Excess Reporting');
	include('includes/header.inc');

	echo '<p class="page_title_text"><img src="'.$rootpath.'/css/'.$theme.'/images/inventory.png" title="' . _('Stock') . '" alt="" />' . ' ' . $title . '</p>';

	echo '<form action=' . htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') . ' method="post">';
	echo '<input type="hidden" name="FormID" value="' . $_SESSION['FormID'] . '" />';
	echo '<table class="selection">';
	echo '</select></td></tr>';
	echo '<tr><td>' . _('Inventory Category') . ':</td><td><select name="CategoryID">';
	echo '<option selected="True" value="All">' . _('All Stock Categories') . '</option>';
	$sql = "SELECT categoryid,
				   categorydescription
			FROM stockcategory";
	$result = DB_query($sql,$db);
	while ($myrow = DB_fetch_array($result)) {
		echo '<option value="' . $myrow['categoryid'] . '">' . $myrow['categoryid'] . ' - ' .$myrow['categorydescription'] . '</option>';
	} //end while loop
	echo '<tr><td>' . _('Sort') . ':</td><td><select name="Sort">';
	echo '<option selected="True" value="extcost">' . _('Extended Shortage Dollars').'</option>';
	echo '<option value="stockid">' . _('Part Number').'</option>';
	echo '</select></td></tr>';

	echo '<tr><td>' . _('Shortage-Excess Option') . ':</td><td><select name="ReportType">';
	echo '<option selected="True" value="Shortage">' . _('Report MRP Shortages').'</option>';
	echo '<option value="Excess">' . _('Report MRP Excesses').'</option>';
	echo '</select></td></tr>';

	echo '<tr><td>' . _('Print Option') . ':</td><td><select name="Fill">';
	echo '<option selected="True" value="yes">' . _('Print With Alternating Highlighted Lines').'</option>';
	echo '<option value="no">' . _('Plain Print').'</option>';
	echo '</select></td></tr>';
	echo '</table><br /><div class="centre"><input type="submit" name="PrintPDF" value="' . _('Print PDF') . '" /></div>';

	include('includes/footer.inc');

} /*end of else not PrintPDF */


function PrintHeader(&$pdf,&$YPos,&$PageNumber,$Page_Height,$Top_Margin,$Left_Margin,
					 $Page_Width,$Right_Margin) {

$line_height=12;
/*PDF page header for MRP Shortages report */
if ($PageNumber>1){
	$pdf->newPage();
}

$FontSize=9;
$YPos= $Page_Height-$Top_Margin;

$pdf->addTextWrap($Left_Margin,$YPos,300,$FontSize,$_SESSION['CompanyRecord']['coyname']);

$YPos -=$line_height;
if ($_POST['ReportType'] == 'Shortage'){
$pdf->addTextWrap($Left_Margin,$YPos,300,$FontSize,_('MRP Shortages Report'));
}else{
	$pdf->addTextWrap($Left_Margin,$YPos,300,$FontSize,_('MRP Excess Report'));
}

$pdf->addTextWrap($Page_Width-$Right_Margin-110,$YPos,160,$FontSize,_('Printed') . ': ' .
	 Date($_SESSION['DefaultDateFormat']) . '   ' . _('Page') . ' ' . $PageNumber,'left');

$YPos -=(2*$line_height);

/*Draw a rectangle to put the headings in	 */

//$pdf->line($Left_Margin, $YPos+$line_height,$Page_Width-$Right_Margin, $YPos+$line_height);
//$pdf->line($Left_Margin, $YPos+$line_height,$Left_Margin, $YPos- $line_height);
//$pdf->line($Left_Margin, $YPos- $line_height,$Page_Width-$Right_Margin, $YPos- $line_height);
//$pdf->line($Page_Width-$Right_Margin, $YPos+$line_height,$Page_Width-$Right_Margin, $YPos- $line_height);

/*set up the headings */
$Xpos = $Left_Margin+1;

$pdf->addTextWrap($Xpos,$YPos,130,$FontSize,_('Part Number'), 'left');
$pdf->addTextWrap(130,$YPos,150,$FontSize,_('Description'), 'left');
$pdf->addTextWrap(285,$YPos,20,$FontSize,_('M/B'), 'right');
$pdf->addTextWrap(305,$YPos,55,$FontSize,_('Unit Cost'), 'right');
$pdf->addTextWrap(360,$YPos,50,$FontSize,_('Supply'), 'right');
$pdf->addTextWrap(410,$YPos,50,$FontSize,_('Demand'), 'right');
if ($_POST['ReportType'] == 'Shortage'){
$pdf->addTextWrap(460,$YPos,50,$FontSize,_('Shortage'), 'right');
$pdf->addTextWrap(510,$YPos,60,$FontSize,_('Ext. Shortage'), 'right');
}else{
	$pdf->addTextWrap(460,$YPos,50,$FontSize,_('Excess'), 'right');
	$pdf->addTextWrap(510,$YPos,60,$FontSize,_('Ext. Excess'), 'right');
}
$FontSize=8;
$YPos =$YPos - (2*$line_height);
$PageNumber++;
} // End of PrintHeader function

?>