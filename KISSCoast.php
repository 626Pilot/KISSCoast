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

    echo "Usage: [php -q] KISSCoast.php --coast=x.xx --primePillarCoast=y.yy --file=yourfile.gcode\n" .
         "       [--verbose] [--backup] [--overwrite] [--processes=x]\n" .
         "       coast              How many units (mm) before destring to stop extruding\n" .
         "       primePillarCoast   How much to extrude on the prime pillar (1.0 = slicer default)\n" .
         "       file               Name of G-code file\n" .
         "       verbose            Whether to spew a load of debugging info\n" .
         "       backup             Whether to save a backup as filename.gcode_backup\n" .
         "       overwrite          Whether to overwrite the file, or save as filename.gcode_out\n" .
         "       processes          How many processes to spawn\n" .
         "\n" .
         "Process worker options (only developers have to care about these):\n" .
         "       processID          Process ID (only if this is a spawned process)\n" .
         "       processMasterID    Master process's UUID (only if this is a spawned process)\n" .
         "\n";

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
    'coast:',										// How many units (mm) before destring to stop extruding
    'primePillarCoast:',							// How much to extrude on the prime pillar (1.0 = slicer default)
    'file:',										// Name of G-code file, which will be overwritten
    'verbose',										// Whether to spew a load of debugging info
    'backup',										// Whether to save a backup as filename.gcode_backup
    'overwrite',									// Whether to overwrite the file, or save as filename.gcode_out
    'processes:',									// How many processes to spawn
    'processID:',									// Process ID (only if this is a spawned process)
    'processMasterID:'								// Master process's UUID (only if this is a spawned process)
);
$options = getopt("", $longopts);

// Sanity check
if(@ !is_numeric($options['primePillarCoast']) || @ $options['primePillarCoast'] < 0 || @ $options['primePillarCoast'] > 50) {
    echo "ERROR: primePillarCoast must be a number between 0 and 50.\n";
    displayHelp();
    exit(1);
}

if(@ !is_numeric($options['coast']) || @ $options['coast'] < 0 || @ $options['coast'] > 50) {
    echo "ERROR: coast must be a number between 0 and 50.\n";
    displayHelp();
    exit(2);
}

if(@ !file_exists($options['file']) && $options['processMasterID'] == null) {
    echo "ERROR: File '${options['file']}' doesn't exist.\n";
    displayHelp();
    exit(3);
}

// Verbose output?
global $verbose;
$verbose = false;
if(array_key_exists("verbose", $options)) {
    $verbose = true;
    debug("Verbose output on.\n");
}

// Make backup file?
if(array_key_exists("backup", $options)) {
    copy($options['file'], $options['file'] . "_backup");
    debug("Backup file created: " . $options['file'] . "_backup\n");
}

// Handle multiprocessing.
// Some PHP package maintainers (I'm looking at you, Ubuntu) don't think it's necessary to supply
// PHP with threading support! I strongly disagree with this. Anyway, we'll spawn some copies of
// this process, and let the OS figure out which cores to run them on.
$processes = 1;
$processID = null;
$isProcessWorker = false;

// Set up process master ID. The working directory will be created under that ID.
// This way, if for some reason you're running multiples of this script in the same directory,
// they won't step on each others' work.
@ $processMasterID = $options['processMasterID'];
if($processMasterID == null) {
    $processMasterID = uniqid();
    debug("Generated process master ID $processMasterID. (Master)\n");
} else {
    debug("Got process master ID $processMasterID. (Worker)\n");
}

// If the 'processes' option is set, we're going to operate as the controlling process.
if(@ $options['processes']) {

    $processes = $options['processes'];
    debug("Will run in multiprocessing mode. Processes: $processes\n");

    // Sanity check
    if($processes < 1 || $processes > 32) {
        echo "ERROR: Can't have <1 or >32 processes. Try 4 or 8 to start.\n";
        displayHelp();
        exit(4);
    }
}

// If we were called with a process ID, we're a worker
$processDir = "KISSCoast_wd_$processMasterID";
if(@ $options['processID']) {

    $processID = $options['processID'];
    debug("Will run as a worker process. Directory=$processDir. Process ID=$processID.\n");
    
    if(!is_dir($processDir)) {
        echo "ERROR: Process directory '$processDir' doesn't exist.\n";
        exit(5);
    }
    
    if(!is_numeric($processID) || $processID < 0) {
        echo "ERROR: Process ID '$processID' is either non-numeric or negative.\n";
        exit(6);
    }
    
    $isProcessWorker = true;

}
    
// If master, create a working directory for the processes
if(!$isProcessWorker) {

    debug("Creating working directory for processes in '$processDir'.\n");
    if(!mkdir($processDir)) {
        echo "ERROR: Couldn't create working directory for processes.\n";
        exit(6);
    }

}

$workerInputFile = null;
$workerOutputFile = null;



// Read the input file.
// KISSlicer outputs files with DOS-style line termination (CR+LF).
if($isProcessWorker) {
    debug("Reading process worker file.\n");
    $workerInputFile = "$processDir/$processID.in";
    if(!$input = file($workerInputFile, FILE_IGNORE_NEW_LINES)) {
        echo "ERROR: Couldn't open process input file $workerInputFile.\n";
        exit(7);
    }
} else {
    debug("Reading file.\n");
    if(!$input = file($options['file'], FILE_IGNORE_NEW_LINES)) {
        echo "ERROR: Couldn't open input file ${options['file']}.\n";
        exit(8);
    }
    debug("Read input file ${options['file']}.\n");
}

// Open output file.
if($isProcessWorker) {

    // This is just one worker process
    $workerOutputFile = "$processDir/$processID.out";
    $output = fopen($workerOutputFile, 'w');
    debug("Opened thread worker output file at $workerOutputFile.\n");

} else {

    // Either master, or single-processed
    debug("Opened master output file at ");

    if(array_key_exists("overwrite", $options)) {
        $output = fopen($options['file'], 'w');
        debug($options['file'] . ".\n");
    } else {
        $output = fopen($options['file'] . "_out", 'w');
        debug($options['file'] . "_out.\n");
    }

}

// Write header to output file
if($isProcessWorker) {

    // Each worker process's section is marked
    fwrite($output, "; -------------------------------------------------------------------------------\n");
    fwrite($output, "; KISSCoast Intermediate Output - Process #$processID\n");
    fwrite($output, "; -------------------------------------------------------------------------------\n");

} else {

    // This goes at the top of the main output file
    fwrite($output, "; Coasting implemented by KISSCoast.php\n");
    fwrite($output, "; -------------------------------------------------------------------------------\n");
    fwrite($output, ";             Author: 626Pilot (find me on the SeeMeCNC forums)\n");
    fwrite($output, ";             Source: https://github.com/626Pilot/KISSCoast\n");
    fwrite($output, ";          Timestamp: " . date("Y-m-d H:i:s", time()) . "\n");
    fwrite($output, ";              coast: ${options['coast']}\n");
    fwrite($output, ";   primePillarCoast: ${options['primePillarCoast']}\n");

}

// Flush file contents so far to disk
flush();

// Setup
$isPrimePillar = false;			// Whether we are currently drawing a prime pillar loop
$mmToCoast = 0;					// How many mm to coast at end of path
$stats = array(
    'regular' => 0,
    'prime' => 0,
    'regskipped' => 0,
    'primeskipped' => 0
);

// Iterate over input file
debug("Input file has " . count($input) . " lines.\n");

if($isProcessWorker || $processes == 1) {

    if($isProcessWorker) {
        debug("This is a thread worker.\n");
    } else {
        debug("This is a single-process instance.\n");
    }

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

            /*  Scan back in $input[], noting the distance traveled with each G1 move.

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
                    if($isPrimePillar) {
                        $stats['primeskipped']++;
                    } else {
                        $stats['regskipped']++;
                    }
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
                        
                        // Save feedrate
                        $feedrate = $seg2['f'];
                        
                        // Figure out how much to extrude
                        $eDist = ($seg2['e'] - $seg1['e']);
                        $splitE = $seg1['e'] + ($eDist * $distRatio);
                        debug("eDist: $eDist / splitE: $splitE\n");
                        
                        // Build the two new lines we'll insert, including Z coordinates if supplied
                        //$nl1 = "G1 X${splitPoint['x']} Y${splitPoint['y']} E$splitE";
                        $nl1 = sprintf("G1 X%1.4f Y%1.4f E%1.4f", $splitPoint['x'], $splitPoint['y'], $splitE);
                        if($seg1['z'] != null) {
                            $nl1 .= " Z" . $seg1['z'];
                        }
                        //$nl2 = "G1 X${endXY['x']} Y${endXY['y']}";
                        $nl2 = sprintf("G1 X%1.4f Y%1.4f", $endXY['x'], $endXY['y']);
                        if($seg2['z'] != null) {
                            $nl2 .= " Z" . $seg2['z'];
                        }
                        
                        // Append feedrate, if specified
                        if($feedrate) {
                            $nl1 .= " F$feedrate";
                            $nl2 .= " F$feedrate";
                        }

                        // Make these points easier to find in the output
                        $nl1 .= " ; Calculated endpoint of extrusion";
                        $nl2 .= " ; Begin coast ($mmToCoast mm)";
                        
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
                            $input[$filePos + 1] .= " ; Skipping tiny segment and beginning coast ($mmToCoast mm)";
                        }

                        // Update stats
                        if($isPrimePillar) {
                            $stats['prime']++;
                        } else {
                            $stats['regular']++;
                        }

                        // Strip extruder moves from remaining lines in this path
                        for($y = $filePos + 1; $y < $x; $y++) {
                            debug("- Stripping E-axis moves from line: '${input[$y]}'\n");
                            $input[$y] = stripE($input[$y]);
                            debug("                            Result: '${input[$y]}'\n");
                        }

                        debug("\n/!\\ Done with this path.\n \n");
                        $isPrimePillar = false;
                        $done = true;

                    } // if($lengthToPathEnd > $mmCoast)

                } // if($done == false && $endXY != false)

            } // for($filePos = $x - 2; !$done && $x >= 0; $filePos--)

        } // if(strstr($line, $triggers['destring']))

    } // for($x=0; $x<count($input); $x++)

} // if($isProcessWorker)

if(!$isProcessWorker && $processes > 1) {

    // This is the master process, so we're going to parcel the file out in chunks.
    debug("This is the master process.\n");

    $nLines = count($input);
    $chunkSize = intval($nLines / $processes);
    debug("Chunk size: $chunkSize\n");

    // Make sure we don't get a destring command in the middle of a chunk boundary
    $chunkStart = $chunkEnd = 0;
    $workerProcesses = array();
    for($x = 1; $x <= $processes; $x++) {
    
        $chunkEnd = intval($x * $chunkSize);
        debug("Chunk $x starts at line $chunkStart\n");
        
        // Scan until after we find a destring
        $done = false;
        $foundDestring = false;
        for($y = $chunkEnd; $y < $nLines && !$done; $y++) {

            // Check for destring
            if(strstr($input[$y], $triggers['destring'])) {
                debug("Found destring at line $y\n");
                $foundDestring = true;
            }
            
            if($foundDestring && trim($input[$y]) == ";") {
                debug("Found terminal comment after destring at line $y. This is the chunk end.\n");
                $chunkEnd = $y;
                $done = true;
            }
        
        }

        // Still need to write the final chunk here.
        if($x == $processes) {
            debug("Final process, writing to EOF.\n");
            $chunkEnd = $nLines - 1;	// Minus one because first key is 0, not 1.            
        }
        
        debug("Chunk ends at $chunkEnd. Writing KISSCoast_wd_$processMasterID/$x.out.\n");
        $chunkFileName = "$processDir/$x.in";
        if(!$chunkFile = fopen($chunkFileName, 'w')) {
            echo "ERROR: Couldn't open chunk file for writing.\n";
            exit(10);
        }
        debug("Writing lines from $chunkStart to $chunkEnd.\n");
        for($y = $chunkStart; $y <= $chunkEnd; $y++) {
            fwrite($chunkFile, $input[$y] . "\n");
        }
        fclose($chunkFile);
        
        $chunkStart = $chunkEnd + 1;
        
        debug("Writing complete. Opening process...\n");

        $descriptorSpec = array(
           0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
           1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
           2 => array("file", "$processDir/error-output-$x.txt", "a") // stderr is a file to write to
        );

        $cwd = getcwd();
        $env = null; //array('some_option' => 'aeiou');
        $cmd = "./KISSCoast.php --coast=${options['coast']} --primePillarCoast=${options['primePillarCoast']} " .
               "--processMasterID=$processMasterID --processID=$x";
        debug("Worker $x cmd: $cmd\n \n");

        // Open the actual process
        $workerProcesses[$x]['proc'] = proc_open($cmd, $descriptorSpec, $pipes, $cwd, $env);

        // Set pipe to non-blocking and save for later
        stream_set_blocking($pipes[1], 0);
        $workerProcesses[$x]['pipes'] = $pipes;

    }

    // Wait for worker processes to finish
    debug("Waiting for worker processes to finish...\n");
	$done = false;
	while(!$done) { 
		$runningProcs = 0;
        for($x = 1; $x <= $processes; $x++) {
            if(is_resource($workerProcesses[$x]['proc'])) {
				$procInput = stream_get_contents($workerProcesses[$x]['pipes'][1]);
				if(trim($procInput) != null) {
				    echo "Process $x returned input '$procInput'.\n";
                }
                if(!feof($workerProcesses[$x]['pipes'][1])) {
                    $runningProcs++;
				} else {
				    debug("Got FEOF from process $x.\n");
				    proc_close($workerProcesses[$x]['proc']);
				}
			}
		}
		if($runningProcs == 0) {
		    debug("No running processes left!\n");
		    $done = true;
		}
	}

	// Coalesce output files into the input array
	for($x = 1; $x <= $processes; $x++) {
	    debug("Writing intermediate output to final output (#$x)\n");
	    $input = file_get_contents("$processDir/$x.out");
	    fwrite($output, $input);
	    
	    // Delete intermediate files
	    unlink("$processDir/$x.in");
	    unlink("$processDir/$x.out");
	    unlink("$processDir/error-output-$x.txt");
	}

} else {

    // Single process
    // Stats
    fwrite($output, ";      Regular paths: ${stats['regular']} coasted, ${stats['regskipped']} skipped (too short)\n");
    fwrite($output, "; Prime pillar paths: ${stats['prime']} coasted, ${stats['primeskipped']} skipped (too short)\n");
    fwrite($output, "; -------------------------------------------------------------------------------\n");

    // Write from memory
    foreach($input as $line) {
        fwrite($output, $line . "\r\n");
    }
}
//if($verbose) {
//    var_dump($input);
//}

// Coalesce worker process output and remove scratch files
if($processes > 1) {
    if(!rmdir($processDir)) {
        echo "NOTICE: Unable to remove work directory '$processDir'.\n";
    }
}

// Close output stream
fclose($output);

debug("\nDone.\n");

?>