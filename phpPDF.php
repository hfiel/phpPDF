<?
ini_set('max_execution_time', 300);
require_once("lib/tcpdf/tcpdf.php");

function showError($errMesg) {
	error_log("phpPDF: ".$errMesg);
	$response = array(
		"error" => "phpPDF: ".$errMesg);
	
	header("Content-Type: application/json");  
	echo stripslashes(json_encode($response));	
	exit(1);
}

function applyNewFont($pdf, $newFont) {
	$newFamily = getOptionalParam("family",$newFont,$pdf->FontFamily);
	
	$newStyle = getOptionalParam("style",$newFont,$pdf->FontStyle);
	
	$newSize = getOptionalParam("size",$newFont,$pdf->FontSize);

	$pdf->SetFont($newFamily, $newStyle, $newSize);
}

function addTextItem($pdf, $textItem, $idx) {
	$text = getRequiredParam("text",$textItem,$idx);

	$align = getOptionalParam("align",$textItem,"L");
	
	$newLine = getOptionalParam("newLine",$textItem,true);
	$newLine = $newLine?1:0;

	$height = getLineHeight($pdf);
	// We decode the string as in PDFs content UTF8 is not supported
	$pdf->Cell(0, $height, utf8_decode($text),0,$newLine,$align);
}

function addParItem($pdf, $parItem, $idx) {
	$text = getRequiredParam("text",$parItem,$idx);
	$align = getOptionalParam("align",$parItem,"L");
	$width= getOptionalParam("width", $parItem, 0);

	$lineHeight = getLineHeight($pdf);
	$pdf->MultiCell($width,$lineHeight, $text, 0, $align);	
}

function addImageItem($pdf, $imageItem, $idx) {
	$pngImage = null;

	if(array_key_exists("url", $imageItem)) {

		// Image url can be a file in the server's filesystem, an url, or a data uri.
		$imageURL  = $imageItem["url"];

		if(strpos($imageURL,"data:")===0) {
			$pngImage = imageFromDataUri($imageURL, $idx);
		} else  {
			// We try retrieving a remote image.
			$pngImage = imageFromRemoteUrl($imageURL, $idx);
		}

	} else if(array_key_exists("fileInputName", $imageItem)) {
		// The file came as an uploaded file in an multipart post request.
		$fileInputName = $imageItem["fileInputName"];		
		$pngImage = imageFromUpload($fileInputName, $idx);
	} else {
		showError("Either 'url' or 'fileInputName' must be specified for imageItem at position $idx");
	}

	// We retrieve the image's size.
	$imageWidthPx = imagesx($pngImage);	
	$imageHeightPx = imagesy($pngImage);

	// Conversion between mm and px. 1 inch = 25.4 mm, and standard PDF resolution is 72 dpi (px/inch).
	$pxToMM = 25.4/72;

	$iWidth = $imageWidthPx*$pxToMM;
	$iHeight = $imageHeightPx*$pxToMM;
	
	// Optional params "width" and "height"	
	$width = $iWidth;
	$height = $iHeight;
	if(array_key_exists("width",$imageItem) && array_key_exists("height",$imageItem)) {
		$width = $imageItem["width"];
		$height = $imageItem["height"];
	} else if(array_key_exists("width",$imageItem)){
		// If only one param is specified, we keep the aspect ratio.
		$width = $imageItem["width"];
		$height = $width*$iHeight/$iWidth;
	} else if(array_key_exists("height",$imageItem)){
		$height = $imageItem["height"];
		$width = $height*$iWidth/$iHeight;
	} 
	
	// We save the image in a tmp file to be able to load it.
	$imagePath = tempnam(sys_get_temp_dir(),"imageTmp");

	imagealphablending($pngImage, false);
	imagesavealpha($pngImage, true);
	imagepng($pngImage, $imagePath, 1);

	// We specify PNG as the format as we always convert the image or PDF to PNG.
	$pdf->Image($imagePath, $pdf->GetX(), $pdf->GetY(), $width, $height, "PNG");

	$pdf->SetY($pdf->GetY()+$height);

	unlink($imagePath);

	imagedestroy($pngImage);
}

function imageFromDataUri($imageUrls, $idx) {
	// We get the image's content.
	$imgData = base64_decode(substr($imageURL, strpos($imageURL, ",")+1));

	$pngImage = imageFromContents($imgData, $idx);
	if(!$pngImage) {
		showError("The data uri provided as url parameter for image item at position $idx doesn't contain a valid image or PDF.");
	}

	return $pngImage;
}

function imageFromRemoteUrl( $url, $idx) {
	// Defining the default CURL options
	$defaults = array( 
	        CURLOPT_URL => $url, 
	        CURLOPT_RETURNTRANSFER => TRUE	  
	 ); 

	// Open the Curl session
	$session = curl_init();		    

	// Setting the options
	curl_setopt_array($session, $defaults);		    

	// Make the call
	$imgResp = curl_exec($session);	
	

	// Handle response
	$srcImage = null;
	if($imgResp) {
	    $srcImage = imageFromContents($imgResp);
	}  else {
		showError("Curl couldn't retrieve the image specified in the image item at position $idx. Error was: ".curl_error($session));
	}

	if(!$imgResp) {
		showError("The url specified for image item at position $idx didn't contain a valid image.");
	}

	// Close the connetion
	curl_close($session);

	return $srcImage;
}

function imageFromUpload($formFieldName, $idx) {
	if(!array_key_exists($formFieldName, $_FILES)) {
		showError("No uploaded file found for file input name '$fileInputName' specified for item at position $idx");
	}

	// We try opening the uploaded file we don't trust mime type or extensions.
	$filePath = $_FILES[$formFieldName]["tmp_name"];


	$resultImg = imageFromContents(file_get_contents($filePath));

	if(!$resultImg){
		showError("The image uploaded in field '$formFieldName' for image item at $idx has an invalid format.");
	}
	
	unlink($filePath);

	return $resultImg;

}

function imageFromContents($fileContents) {
	$img = @imagecreatefromstring($fileContents);


	if(!$img) {
		// We try converting this from pdf.
		$tmpPDFInput = tempnam(sys_get_temp_dir(), "pdfInput");
		file_put_contents($tmpPDFInput, $fileContents);

		
		$img = new imagick(); // [0] can be used to set page number

	   
	    $img->setResolution(175,175);	
	    $img->readImage($tmpPDFInput);
	        
	    $img->resetIterator();

	    $img = $img->appendImages(true);


	    $img->setImageFormat( "png" );

	    $img->setImageUnits(imagick::RESOLUTION_PIXELSPERINCH);
	    

	    $data = $img->getImageBlob(); 


	    $img = imagecreatefromstring($data);

		unlink($tmpPDFInput);
	}
	return $img;
}

function getFormatFromMimeType ($mimeType, $idx) {
	$barIdx = strpos($mimeType,"/");
	if($barIdx<=0) {
		showError("Mime type for uploaded file or data uri specified for imageItem at position $idx is not valid");
	}

	$format = substr($mimeType, $barIdx+1);
	return $format;
}



function addTableItem($pdf, $tableItem, $idx) {

	$rows = getRequiredParam("rows", $tableItem, $idx);

	$borderWidth = getOptionalParam("borderWidth",$tableItem,0.3);

	$left = $pdf->GetX();
	$top = $pdf->GetY();

	$pdf->setLineWidth($borderWidth);
	$pdf->setDrawColor(0,0,0);

	$htmlTable = '<table border="1" cellpadding="4">';

	$vAlignHeightHack = $pdf->GetFontSize()*2;

	for($rowIdx = 0; $rowIdx < count($rows); $rowIdx++) {
		$htmlTable.="<tr>";

		$row = $rows[$rowIdx];
		for($colIdx=0; $colIdx < count($row) ; $colIdx++){
			$column = $row[$colIdx];

			$colSpan = 1;
			$rowSpan = 1;
			$hAlign = "left";
			$vAlign = "top";
			$columnText = $column;
			$width = "auto";

			if(is_array($column)) {
				if(!array_key_exists("text",$column)) {
					showError("'text' must be defined for the cell $cIdx of row $rIdx of tableItem at position $idx");
				}
				$columnText = $column["text"];

				$colSpan = getOptionalParam("colspan", $column, 1);
				$rowSpan = getOptionalParam("rowspan", $column, 1);
				$hAlign = getOptionalParam("align", $column, "left");
				$vAlign = getOptionalParam("valign", $column, "top");
				$width = getOptionalParam("width",$column,"auto");
			}

			if($rowSpan>1) {
				switch ($vAlign) {
					case 'bottom':
						$columnText="<span style=\"font-size: $vAlignHeightHack;\">"
							.str_repeat('&nbsp;<br/>', $rowSpan).'</span>'.$columnText;
						break;
					case 'middle':
						$columnText="<span style=\"font-size: $vAlignHeightHack;\">"
							.str_repeat('&nbsp;<br/>', $rowSpan-1).'</span>'.$columnText;
						break;
				}
			}

			if($width==="auto") {
				$htmlTable.="<td align=\"$hAlign\" colspan=\"$colSpan\" rowspan=\"$rowSpan\">$columnText</td>";
			} else {
				$width.="mm";
				$htmlTable.="<td align=\"$hAlign\" colspan=\"$colSpan\" rowspan=\"$rowSpan\" width=\"$width\">$columnText</td>";	
			}
			
		}

		$htmlTable.="</tr>";
	}
	
	$htmlTable.="</table>";

	$pdf->writeHTML($htmlTable, false, false, false, false, '');
}

function addItem ($pdf, $item, $idx) {
	// Here we do the common stuff.
	if(array_key_exists("newFont", $item)) {
		applyNewFont($pdf, $item["newFont"]);
	}

	$type = getOptionalParam("type",$item,"text");


	$x = $pdf->GetX();
	if(array_key_exists("x", $item)) {
		// Absolute positioning.
		$x = intval($item["x"]);

	} else if(array_key_exists("dx", $item)) {
		// Relative
		$x+=intval($item["dx"]);
	}
	

	$y = $pdf->GetY();
	if(array_key_exists("y", $item)) {
		// Absolute positioning.
		$y = intval($item["y"]);
	} else if(array_key_exists("dy", $item)) {
		// Relative
		$y+=intval($item["dy"]);
	}

	$pdf->SetXY($x,$y);

	switch(strtolower($type)) {
		case "text":
			addTextItem($pdf, $item, $idx);
			break;
		case "paragraph":
		case "par":
		 	addParItem($pdf, $item, $idx);
			break;
		case "image":
			addImageItem($pdf, $item, $idx);
			break;
		case "table":
			addTableItem($pdf, $item, $idx);
			break;
		default:
			showError("Unsupported item type for item ". $idx);
	}

}


function getRequiredParam($paramName, $item, $itemIdx) {
	if(!array_key_exists($paramName, $item)) {
		showError("'$paramName' property needs to be specified for item at position $idx.");
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

function getLineHeight($pdf) {
	// A possibly very bad appoximation.
	return  $pdf->GetStringWidth("x")*1.5;
}

$params = null;


if(array_key_exists("params", $_GET)) {
	$params =$_GET["params"];
} else if(array_key_exists("params",$_POST)) {
	$params = $_POST["params"];
}

$response = array();

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

$paperSize = getOptionalParam("size",$params,"A4");
$margin = getOptionalParam("margin", $params, 30);

$items = array();
if(array_key_exists("items",$params)) {
	$items = $params["items"];
} else {
	showError("At least one item must be defined! ".$params);
}


$outputFormat = getOptionalParam("outputFormat", $params, "PDF");
if(!in_array($outputFormat, ["PDF","PNG"])) {
	showError("Output format must be one of: 'PDF','PNG'");
}

$pdf = new TCPDF("P","mm",$paperSize);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins($margin, $margin);
$pdf->AddPage();
$pdf->SetFontSize(12);
for($i=0; $i < count($items); $i++) {
	addItem($pdf, $items[$i], $i);
}


$pdf->Output();	

