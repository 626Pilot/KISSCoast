#!/usr/bin/php -q
<?php

/*		KISSCoast.php by 626Pilot
 *		-------------------------
 *		Tab: 4 spaces
 *		Implements coast functionality to normal and prime pillar paths in
 *		G-code output by KISSlicer. This allows the filament ooze to exhaust
 *		itself before reaching the end of the path, hopefully cutting down on
 *		blobbing and stringing.
 */


// Tell the user how to use this script
function displayHelp() {
    echo "Usage: [php -q] KISSCoast.php --coast=x.xx --primePillarCoast=x.xx --file=yourfile.gcode [--verbose]\n";
}

// Spew debug output if requested
function debug($str) {
    global $verbose;
    if($verbose) {
        echo $str;
    }
}

// Extract X, Y, Z, and E coords from a G-code command.
// Returns an array containing x, y, z, e, and f variables, or null if no XYZ move was found.
function gcodeToXYZEF($gcode) {

    $out = array(
        'x' => null,
        'y' => null,
        'z' => null,
        'e' => null,
        'f' => null
    );
    $line = split(" ", $gcode);
    $isG1 = false;
    $varSet = false;

    foreach($line as $token) {
        if(substr($token, 0, 2) == "G1") {
          $isG1 = true;
        }
        if($isG1) {
            if(substr($token, 0, 1) == 'X') {
                $out['x'] = floatval(substr($token, 1));
                $varSet = true;
            }
            if(substr($token, 0, 1) == 'Y') {
                $out['y'] = floatval(substr($token, 1));
                $varSet = true;
            }
            if(substr($token, 0, 1) == 'Z') {
                $out['z'] = floatval(substr($token, 1));
                $varSet = true;
            }
            if(substr($token, 0, 1) == 'E') {
                $out['e'] = floatval(substr($token, 1));
                // We don't set $varSet here because we don't care about lines without XYZ moves.
            }
            if(substr($token, 0, 1) == 'F') {
                $out['f'] = floatval(substr($token, 1));
                // Ditto.
            }
        }
    }

    if($varSet == false) {
        return false;
    } else {
      return $out;
    }

}

// Strip extruder (E-axis) move from a G-code command
function stripE($gcode) {
    $line = split(" ", $gcode);
    $out = array();
    foreach($line as $token) {
        if($token[0] != 'E') {
            $out []= $token;
        }
    }
    return join(" ", $out);
}


// Return distance between two points on a 2-space plane
function distance2D(array $point1, array $point2) {
    $xDist = $point2['x'] - $point1['x'];
    $yDist = $point2['y'] - $point1['y'];
    return sqrt($xDist*$xDist + $yDist*$yDist);
}

// Strings we'll look for
$triggers = array(
    'primePillar' => "Prime Pillar Path",			// We slow down extrusion on prime pillars
    'destring' => "Destring/Wipe/Jump Path",		// We coast at the end of a jump path
    'empty' => ";"									// KISS puts this char on empty lines
);

// Command line options
$longopts  = array(
    'coast:',										// How many MM before destring to stop extruding
    'primePillarCoast:',							// How much to extrude on the prime pillar (1.0 = slicer default)
    'file:',										// Name of G-code file, which will be overwritten
    'verbose',										// Whether to spew a load of debugging info
    'backup',										// Whether to save a backup as filename.gcode_backup
    'overwrite'										// Whether to overwrite the file, or save as filename.gcode_out
);
$options = getopt("", $longopts);

// Sanity check
if(@ !is_numeric($options['primePillarCoast']) || @ $options['primePillarCoast'] < 0 || @ $options['primePillarCoast'] > 20) {
    echo "ERROR: primePillarCoast must be a number between 0 and 20.\n";
    displayHelp();
    exit(1);
}

if(@ !is_numeric($options['coast']) || @ $options['coast'] < 0 || @ $options['coast'] > 20) {
    echo "ERROR: coast must be a number between 0 and 20.\n";
    displayHelp();
    exit(2);
}

if(@ !file_exists($options['file'])) {
    echo "ERROR: File '${options['file']}' doesn't exist.\n";
    displayHelp();
    exit(3);
}

// Verbose output?
global $verbose;
$verbose = false;
if(array_key_exists("verbose", $options)) {
    $verbose = true;
}

// Make backup file?
if(array_key_exists("backup", $options)) {
    copy($options['file'], $options['file'] . "_backup");
}

// Read the input file.
// KISSlicer outputs files with DOS-style line termination (CR+LF).
$input = split("\r\n", file_get_contents($options['file']));

// Open output file
if(array_key_exists("overwrite", $options)) {
    $output = fopen($options['file'], 'w');
} else {
    $output = fopen($options['file'] . "_out", 'w');
}

// Write header to output file
fwrite($output, "; Coasting implemented by KISSCoast.php\n");
fwrite($output, "; -------------------------------------------------------------------------------\n");
fwrite($output, ";           Author: 626Pilot (find me on the SeeMeCNC forums)\n");
fwrite($output, ";           Source: https://github.com/626Pilot/KISSCoast\n");
fwrite($output, ";        Timestamp: " . date("Y-m-d H:i:s", mktime()) . "\n");
fwrite($output, ";            coast: ${options['coast']}\n");
fwrite($output, "; primePillarCoast: ${options['primePillarCoast']}\n");
fwrite($output, "; -------------------------------------------------------------------------------\n");
fwrite($output, ";\n");

// Setup
$isPrimePillar = false;				// Whether we are currently drawing a prime pillar loop
$mmToCoast = 0;					// How many mm to coast at end of path

// Iterate over input file
debug("Input file has " . count($input) . " lines.\n");
for($x=0; $x<count($input); $x++) {

    // Read current line
    $line = $input[$x];
    debug("\nProcessing line $x: '$line' || ");

    // Check empty line
    $lastLineEmpty = false;
    if(trim($line) == $triggers['empty']) {
        $lastLineEmpty = true;
    } else {
        $lastLineEmpty = false;
    }
    debug("lastLineEmpty: $lastLineEmpty ");

    // Check start of prime pillar
    if(strstr($line, $triggers['primePillar'])) {
        $isPrimePillar = true;
    }
    debug("isPrimePillar: $isPrimePillar ");

    // Check for destring
    if(strstr($line, $triggers['destring'])) {

        /*	Scan back in $input[], noting the distance traveled with each G1 move.

            Once the total distance >= $mmToCoast, split the segment where that point occurs
            into two segments, the first with enough extrusion to get to the point where we
            want that to end, and the next without any extrusion.

            After that, strip all extrusion commands between that point and the end of the
            path.

            If the point where extrusion needs to stop is less than ten microns from the end
            of the path, we leave that segment alone and start removing extrusion commands
            at the very next line segment. This is to avoid producing teeny tiny moves that
            could potentially make Smoothie freeze, as with the Simplify3D bug.
        */

        // How far we're going to coast depends on whether we're doing a prime pillar or not
        if($isPrimePillar) {
            $mmToCoast = $options['primePillarCoast'];
            $isPrimePillar = false;
        } else {
            $mmToCoast = $options['coast'];
        }

        debug("\n \n/!\\ Found destring command. mm to coast: $mmToCoast\n");

        $lengthFromPathEnd = 0;
        $done = false;
        
        // One line back will be an empty comment, so start from two lines back
        for($filePos = $x - 2; !$done && $x >= 0; $filePos--) {

            $startXY = gcodeToXYZEF($input[$filePos - 1]);
            $endXY = gcodeToXYZEF($input[$filePos]);
            
            // If $startXY is false, we went back past the beginning of the path.
            // That means that the path is too short to coast, and we're done.
            if($startXY == false) {
                debug("This segment is too short to coast - skipping.\n");
                $done = true;
            }
            
            if($done == false && $endXY != false) {	// We're not done, and have a valid line segment
                
                // Process current line segment
                $dist = distance2D($startXY, $endXY);
                $lengthFromPathEnd += $dist;
                debug("- Line segment: <${startXY['x']}, ${startXY['y']}> - <${endXY['x']}, ${endXY['y']}>, $dist mm long, total = $lengthFromPathEnd mm.\n");

                if($lengthFromPathEnd > $mmToCoast) {

                    debug("/!\\ Segment exceeds coast length. dist=$dist\n");

                    // Chop the segment into two, the first with extrusion and the second without.
                    $splitDistFromStart = $lengthFromPathEnd - $mmToCoast;
                    debug("! We will be splitting this line $splitDistFromStart mm from its starting point.\n");

                    $xSlope = $endXY['x'] - $startXY['x'];
                    $ySlope = $endXY['y'] - $startXY['y'];
                    $distRatio = $splitDistFromStart / $dist;
                    debug("! The distance ratio (desired distance / total) is $distRatio.\n");
                    
                    // Perform linear interpolation
                    $splitPoint = array(
                        'x' => (1-$distRatio) * $startXY['x'] + ($distRatio * $endXY['x']),
                        'y' => (1-$distRatio) * $startXY['y'] + ($distRatio * $endXY['y'])
                    );
                    debug("splitPoint at <${splitPoint['x']}, ${splitPoint['y']}>\n");
                    debug("The two lines involved in this segment are:\n");
                    debug("\t" . $input[$filePos - 1] . "\n");
                    debug("\t" . $input[$filePos] . "\n");
                    
                    $seg1 = gcodeToXYZEF($input[$filePos - 1]);
                    $seg2 = gcodeToXYZEF($input[$filePos]);
                    debug("\n");
                    debug("Seg1: <${seg1['x']}, ${seg1['y']}>, E=${seg1['e']}, F=${seg1['f']}\n");
                    debug("Seg2: <${seg2['x']}, ${seg2['y']}>, E=${seg2['e']}, F=${seg2['f']}\n");
                    
                    // Save feedrate, if supplied (it isn't on prime pillars!)
                    $feedrate = $seg2['f'];
                    
                    // Figure out how much to extrude
                    $eDist = ($seg2['e'] - $seg1['e']);
                    $splitE = $seg1['e'] + ($eDist * $distRatio);
                    debug("eDist: $eDist / splitE: $splitE\n");
                    
                    // Build the two new lines we'll insert, including Z coordinates if supplied
                    $nl1 = "G1 X${splitPoint['x']} Y${splitPoint['y']} E$splitE";
                    if($seg1['z'] != null) {
                        $nl1 .= " Z" . $seg1['z'];
                    }
                    $nl2 = "G1 X${endXY['x']} Y${endXY['y']}";
                    if($seg2['z'] != null) {
                        $nl2 .= " Z" . $seg2['z'];
                    }
                    
                    // Append feedrate, if specified
                    if($feedrate) {
                        $nl1 .= " F$feedrate";
                        $nl2 .= " F$feedrate";
                    }
                    
                    debug("\n");
                    debug("New line 1: ${nl1}\n");
                    debug("New line 2: ${nl2}\n");

                    // Replace existing line with 1st new line
                    $input[$filePos] = $nl1;
                    
                    // Insert the 2nd new line after the 1st, but only if it isn't too short
                    $nlDist = distance2D($splitPoint, $endXY);
                    debug("!!! Distance between the two new lines is $nlDist.\n");
                    if($nlDist > 0.01) {
                        array_splice($input, $filePos + 1, 0, $nl2);
                        $filePos++;	// Skip ahead one more (because we inserted a line) before stripping E moves
                        $x++;		// Increment input file pointer so we don't re-process the same line
                    } else {
                        debug("!!! Skipping insertion of second line because the distance is less than 10 microns.\n");
                    }

					// Strip extruder moves from remaining lines in this path
                    for($y = $filePos + 1; $y < $x; $y++) {
                        debug("- Stripping E-axis moves from line: '${input[$y]}'\n");
                        $input[$y] = stripE($input[$y]);
                        debug("                            Result: '${input[$y]}'\n");
                    }

                    debug("\n/!\\ Done with this path.\n \n");
                    $done = true;

                } // if($lengthToPathEnd > $mmCoast)

            } // if($done == false && $endXY != false)

        } // for($filePos = $x - 2; !$done && $x >= 0; $filePos--)

    } // if(strstr($line, $triggers['destring']))

} // for($x=0; $x<count($input); $x++)

// Finally, write the file buffer to disk
foreach($input as $line) {
    fwrite($output, $line . "\r\n");
}

// Close output stream
fclose($output);

debug("\nDone.\n");

?>