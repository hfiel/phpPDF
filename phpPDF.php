<?

ini_set('max_execution_time', 300);

require_once("ParametrizedPDF.php");

function getRequiredParam($paramName, $item, $itemIdx) {
	if(!array_key_exists($paramName, $item)) {
		showError("'$paramName' property needs to be specified for item at position $itemIdx.");
	}

	return $item[$paramName];
}

function getOptionalParam ($paramName, $item, $defaultValue) {
	$result = $defaultValue;
	if(array_key_exists($paramName, $item)) {
		$result = $item[$paramName];
	}

	return $result;
}

function convertPDFFileToImage($filePath) {
	$img = new imagick();

	$img->setResolution(210, 210);
	$img->readImage($filePath);

	$img->resetIterator();

	// If the PDF has several pages, we merge all of them in one image.
	$img = $img->appendImages(true);
	$img->setImageFormat("png");
	$img->adaptiveBlurImage(1, 1);
	$img->setImageUnits(imagick::RESOLUTION_PIXELSPERINCH);

	$data = $img->getImageBlob();

	$img = imagecreatefromstring($data);

	return $img;
}

function showError($errMesg) {
	error_log("phpPDF: ".$errMesg);
	$response = array(
		"error" => "phpPDF: ".$errMesg);

	header("Content-Type: application/json");
	echo stripslashes(json_encode($response));
	exit(1);
}

$params = null;


if(array_key_exists("params", $_GET)) {
	$params =$_GET["params"];
} else if(array_key_exists("params",$_POST)) {
	$params = $_POST["params"];
}

if($params) {
	// We decode the params into an associative array
	$decodedParams = json_decode($params,true);
	if(!$decodedParams) {
		showError("Params parameter isn't valid JSON!: ".$params);	
	}

	$params = $decodedParams;
} else {
	 showError("Params parameter is required!");
}

$outputFormat = getOptionalParam("outputFormat", $params, "PDF");
if(!in_array($outputFormat, array("PDF","PNG"))) {
	showError("Output format must be one of: 'PDF','PNG'");
}

$downloadFile = getOptionalParam("downloadFile", $params, false);
if($downloadFile) {
	// The user wants to download a previously generated file.
	$outputFile = substr($downloadFile, 0, strrpos($downloadFile,"_"));
	$outputFile.=strtolower(".$outputFormat");
	$mimeType = $outputFormat=="PDF"?"application/pdf":"image/png";	

	header("Content-type: $mimeType");
	header("Content-disposition: attachment; filename=$outputFile");
	header('Content-Transfer-Encoding: binary');
	header('Accept-Ranges: bytes');

	// Send Headers: Prevent Caching of File
	header('Cache-Control: private');
	header('Pragma: private');
	header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
	$filePath = sys_get_temp_dir()."/".$downloadFile;
	echo file_get_contents($filePath);
	unlink($filePath);
	exit(0);
}


$paperSize = getOptionalParam("size",$params,"A4");
$margin = getOptionalParam("margin", $params, 30);


if(is_array($margin)) {
	$marginTop = getOptionalParam("top", $margin,30);
	$marginBottom= getOptionalParam("bottom", $margin,30);
	$marginRight = getOptionalParam("right", $margin,30);
	$marginLeft = getOptionalParam("left", $margin,30);
} else {
	$marginTop = $margin;
	$marginBottom = $margin;
	$marginLeft = $margin;
	$marginRight = $margin;
}


$pageOrientation = getOptionalParam("orientation", $params, "P");

$items = array();
if(array_key_exists("items",$params)) {
	$items = $params["items"];
} else {
	showError("At least one item must be defined! ");
}

$header = getOptionalParam("header", $params, false);
$footer = getOptionalParam("footer", $params, false);

$outputFile = getOptionalParam("outputFile",$params, $outputFormat==="PDF"?"doc.pdf":"doc.png");

$pdf = new ParametrizedPDF($pageOrientation,"mm",$paperSize);


// We set the file's metadata. It won't be preserved if output is 'PNG'.
$pdf->SetTitle(getOptionalParam("title",$params,""));
$pdf->SetSubject(getOptionalParam("subject",$params,""));
$pdf->SetCreator(getOptionalParam("creator", $params,"Created with phpPDF and TCPDF!"));
$pdf->SetAuthor(getOptionalParam("author", $params,""));
$pdf->SetKeywords(getOptionalParam("keywords",$params,""));


$pdf->SetMargins($marginLeft, $marginTop, $marginRight,true);
$pdf->SetAutoPageBreak(true, $marginBottom);

if($header) {
	$pdf->setCustomHeader($header);
}

if($footer) {
	$pdf->setCustomFooter($footer);
}

$pdf->AddPage();

$pdf->setHtmlVSpace(array("dt"=>array(0=>array("n"=>0))));

$columns = getOptionalParam("columns", $params, 1);
if($columns > 1) {
	$pdf->setEqualColumns($columns, ($pdf->getPageWidth())/3 -5);
}

$pdf->SetFontSize(12);

// We add items to the pdf!
$pdf->addItems($items);

$keepFile = getOptionalParam("keepFile", $params, false);

if(!$keepFile && $outputFormat==="PDF") {
	$pdf->Output($outputFile,"D");		
} else if(!$keepFile) {
	$tmpPdfOut = tempnam(sys_get_temp_dir(),"pdfOut");

	// We write the file to a tmporal file.
	$pdf->Output($tmpPdfOut,"F");

	$pngImageOut = convertPDFFileToImage($tmpPdfOut);

	// We remove the temporal file, now that we have the image.
	unlink($tmpPdfOut);

	header("Content-type: image/png");
	header("Content-disposition: attachment; filename=$outputFile");
	header('Content-Transfer-Encoding: binary');
	header('Accept-Ranges: bytes');

	// Send Headers: Prevent Caching of File
	header('Cache-Control: private');
	header('Pragma: private');
	header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
	

	imagealphablending($pngImageOut, true);
	//imagesavealpha($pngImageOut, true);
	imagepng($pngImageOut, null, 9);
} else {
	$tmpPdfOut = tempnam(sys_get_temp_dir(), $outputFile."_");

	// We write the file to a tmporal file.	
	$pdf->Output($tmpPdfOut,"F");
	if($outputFormat==="PNG") {
		$pngImageOut = convertPDFFileToImage($tmpPdfOut);
		imagealphablending($pngImageOut, true);
		//imagesavealpha($pngImageOut, true);
		imagepng($pngImageOut, $tmpPdfOut, 9);
	}

	// We return a json object with the download url.
	header("Content-Type: text/html");  
	header('Cache-Control: private');
	header('Pragma: private');
	header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

	$response = array(
		"downloadableFile" => str_replace(sys_get_temp_dir()."/","",$tmpPdfOut)
		);
	// echo out the JSON
	echo stripslashes(json_encode($response));
}




