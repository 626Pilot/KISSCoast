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
         "       minExtrusionLength Minimum extrusion length (shrink/cancel coasting if too short)\n" .
         "       file               Name of G-code file\n" .
         "       verbose            Whether to spew a load of debugging info\n" .
         "       backup             Whether to save a backup as filename.gcode_backup\n" .
         "       overwrite          Whether to overwrite the file, or save as filename.gcode_out\n" .
         "       processes          How many processes to spawn\n" .
         "\n" .
         "Process worker options (only developers have to care about these):\n" .
         "       processID          Process ID (only if this is a spawned process)\n" .
         "       processMasterID    Master process's UUID (only if this is a spawned process)\n" .
         "       keepWorkDir        Don't delete intermediate work files after completion\n" .
         "\n";

}

// Spew debug output if requested
function debug($str="", $newline=true) {
    global $verbose;
    if($verbose) {
        echo $str;
        if($newline) {
            echo "\n";
        }
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
        'f' => null,
        'motion' => false
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
                $out['motion'] = true;
            }
            if(substr($token, 0, 1) == 'Y') {
                $out['y'] = floatval(substr($token, 1));
                $out['motion'] = true;
            }
            if(substr($token, 0, 1) == 'Z') {
                $out['z'] = floatval(substr($token, 1));
                $out['motion'] = true;
            }
            if(substr($token, 0, 1) == 'E') {
                $out['e'] = floatval(substr($token, 1));
            }
            if(substr($token, 0, 1) == 'F') {
                $out['f'] = floatval(substr($token, 1));
            }
        }
    }

    return $out;

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
    'minExtrusionLength:',							// Minimum extrusion length (shrink/cancel coasting if too short)
    'file:',										// Name of G-code file, which will be overwritten
    'verbose',										// Whether to spew a load of debugging info
    'backup',										// Whether to save a backup as filename.gcode_backup
    'overwrite',									// Whether to overwrite the file, or save as filename.gcode_out
    'processes:',									// How many processes to spawn
    'processID:',									// Process ID (only if this is a spawned process)
    'processMasterID:',								// Master process's UUID (only if this is a spawned process)
    'keepWorkDir'									// Don't delete intermediate work files after completion
);
$options = getopt("", $longopts);

// Sanity check
if(@ !is_numeric($options['primePillarCoast']) || @ $options['primePillarCoast'] < 0 || @ $options['primePillarCoast'] > 100) {
    echo "ERROR: primePillarCoast must be a number between 0 and 100.\n";
    displayHelp();
    exit(1);
}

if(@ !is_numeric($options['coast']) || @ $options['coast'] < 0 || @ $options['coast'] > 100) {
    echo "ERROR: coast must be a number between 0 and 100.\n";
    displayHelp();
    exit(2);
}

if(@ !is_numeric($options['minExtrusionLength']) || $options['minExtrusionLength'] < 0) {
    echo "ERROR: minExtrusionLength must be a number greater than zero.\n";
    displayHelp();
    exit(3);
}

if(@ !file_exists($options['file']) && $options['processMasterID'] == null) {
    echo "ERROR: File '${options['file']}' doesn't exist.\n";
    displayHelp();
    exit(4);
}

// Verbose output?
global $verbose;
$verbose = false;
if(array_key_exists("verbose", $options)) {
    $verbose = true;
    debug("Verbose output on.");
}

// Make backup file?
if(array_key_exists("backup", $options)) {
    copy($options['file'], $options['file'] . "_backup");
    debug("Backup file created: " . $options['file'] . "_backup");
}

// Keep work files?
$keepWorkDir = false;
if(array_key_exists("keepWorkDir", $options)) {
    debug("Will keep work directory & files after completion.");
    $keepWorkDir = true;
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
    debug("Generated process master ID $processMasterID. (Master)");
} else {
    debug("Got process master ID $processMasterID. (Worker)");
}

// If the 'processes' option is set, we're going to operate as the controlling process.
if(@ $options['processes']) {

    $processes = $options['processes'];
    debug("Will run in multiprocessing mode. Processes: $processes");

    // Sanity check
    if($processes < 1 || $processes > 128) {
        echo "ERROR: Can't have <1 or >128 processes. Try 32 to start.\n";
        displayHelp();
        exit(5);
    }
}

// If we were called with a process ID, we're a worker
$processDir = "KISSCoast_wd_$processMasterID";
if(@ $options['processID']) {

    $processID = $options['processID'];
    debug("Will run as a worker process. Directory=$processDir. Process ID=$processID.");
    
    if(!is_dir($processDir)) {
        echo "ERROR: Process directory '$processDir' doesn't exist.\n";
        exit(6);
    }
    
    if(!is_numeric($processID) || $processID < 0) {
        echo "ERROR: Process ID '$processID' is either non-numeric or negative.\n";
        exit(7);
    }
    
    $isProcessWorker = true;

}
    
// If master and multiprocessing, create a working directory for the processes
if(!$isProcessWorker && $processes > 1) {

    debug("Creating working directory for processes in '$processDir'.");
    if(!mkdir($processDir)) {
        echo "ERROR: Couldn't create working directory for processes.";
        exit(8);
    }

}

// What the workers will read from, and write to
$workerInputFile = null;
$workerOutputFile = null;

// Read the input file.
// KISSlicer outputs files with DOS-style line termination (CR+LF).
if($isProcessWorker) {
    debug("Reading process worker file.");
    $workerInputFile = "$processDir/$processID.in";
    if(!$input = file($workerInputFile, FILE_IGNORE_NEW_LINES)) {
        echo "ERROR: Couldn't open process input file $workerInputFile.\n";
        exit(9);
    }
} else {
    debug("Reading file.");
    if(!$input = file($options['file'], FILE_IGNORE_NEW_LINES)) {
        echo "ERROR: Couldn't open input file ${options['file']}.";
        exit(10);
    }
    debug("Read input file ${options['file']}.");
}

// Open output file.
if($isProcessWorker) {

    // This is just one worker process
    $workerOutputFile = "$processDir/$processID.out";
    $output = fopen($workerOutputFile, 'w');
    debug("Opened thread worker output file at $workerOutputFile.");

} else {

    // Either master, or single-processed
    debug("Opened master output file at ", false);

    if(array_key_exists("overwrite", $options)) {
        $output = fopen($options['file'], 'w');
        debug($options['file'] . ".");
    } else {
        $output = fopen($options['file'] . "_out", 'w');
        debug($options['file'] . "_out.");
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
    fwrite($output, "; minExtrusionLength: ${options['minExtrusionLength']}\n");

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
debug("Input file has " . count($input) . " lines.");

if($isProcessWorker || $processes == 1) {

    if($isProcessWorker) {
        debug("This is a thread worker.");
    } else {
        debug("This is the master, or a single-process instance.");
    }

    for($x=0; $x<count($input); $x++) {

        // Read current line
        $line = $input[$x];
        debug("\nProcessing line $x: '$line' || ", false);

        // Check empty line
        $lastLineEmpty = false;
        if(trim($line) == $triggers['empty']) {
            $lastLineEmpty = true;
        } else {
            $lastLineEmpty = false;
        }
        debug("lastLineEmpty: $lastLineEmpty ", false);

        // Check start of prime pillar
        if(strstr($line, $triggers['primePillar'])) {
            $isPrimePillar = true;
        }
        debug("isPrimePillar: $isPrimePillar ", false);

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

            debug("\n \n/!\\ Found destring command. mm to coast: $mmToCoast");

            $lengthFromPathEnd = 0;
            $foundPathStart = false;
            $done = false;

            // 1st pass: Determine entire path length
            // One line back will be an empty comment, so start from two lines back
            for($filePos = $x - 2; !$foundPathStart && $filePos >= 0; $filePos--) {

                // Determine current segment endpoints
                $startXY = gcodeToXYZEF($input[$filePos - 1]);	// This may be changed a few lines down
                $endXY = gcodeToXYZEF($input[$filePos]);

                // Check for beginning of path
                if($input[$filePos-1][0] == ";") {
                    $foundPathStart = true;
                    debug("- Found path start. Total path length = $lengthFromPathEnd.");
                }

                // Check for skipping over E-only move
                if(!$startXY['motion']) {
                    // 1st line after comment is the move to the origin of the path.
                    // 2nd line may be an extruder-only move, e.g. to prime on toolchange.
                    // That means we can have a line segment straddling an extruder-only move.
                    // In this case, we simply go back one further.
                    debug("! Skipping over extruder-only move.");
                    $startXY = gcodeToXYZEF(@ $input[$filePos - 2]);	// We use @ because this could scan beyond beginning of file
                }

                // If we have a valid move, add its distance to the length accumulator
                if($startXY['motion'] && $endXY['motion']) {
                    $dist = distance2D($startXY, $endXY);
                    $lengthFromPathEnd += $dist;
                    debug("- Line segment: <${startXY['x']}, ${startXY['y']}> - <${endXY['x']}, ${endXY['y']}>, $dist mm long, total = $lengthFromPathEnd mm.");
                }

            }
            
            $pathLength = $lengthFromPathEnd;

            // Length of whole path: $pathLength:
            // mm to coast: $mmToCoast
            // Min extrusion length: $options['minExtrusionLength']
            
            // If min extrusion length + mm to coast > path length, we have to push the coasting point
            // further out until that's no longer the case.

            $coastDistance = $mmToCoast;
            
            debug();
            debug("Length of whole path: $pathLength");
            debug("mm to coast: $mmToCoast");
            debug("Min extrusion length: ${options['minExtrusionLength']}");
            if($options['minExtrusionLength'] >= $pathLength) {

                // Path length is less than minimum extrusion length, so we can't coast it at all
                debug("Skip this one");
                $spl = sprintf("%1.4f", $pathLength);
                $input[$x - 2] .= " ; Path too short to coast ($spl mm)";
                
                $done = true;
                if($isPrimePillar) {
                    $stats['primeskipped']++;
                } else {
                    $stats['regskipped']++;
                }

            } else if($options['minExtrusionLength'] + $mmToCoast > $pathLength) {

                // Path length is less than min extrusion length + coast length, so push coasting-point closer to end-of-path
                $coastDistance = $pathLength - $options['minExtrusionLength'];
                debug("Move coast point to $coastDistance mm before end-of-path");

            }

            // 2nd pass: Cut path at appropriate length to begin coasting & remove all E-commands after that point
            // One line back will be an empty comment, so start from two lines back
            $lengthFromPathEnd = 0;
            for($filePos = $x - 2; !$done && $filePos >= 0; $filePos--) {

                debug("In 2nd pass on line $filePos");

                $startXY = gcodeToXYZEF($input[$filePos - 1]);
                $endXY = gcodeToXYZEF($input[$filePos]);
                $dist = distance2D($startXY, $endXY);
                $lengthFromPathEnd += $dist;
                debug("- Line segment: <${startXY['x']}, ${startXY['y']}> - <${endXY['x']}, ${endXY['y']}>, $dist mm long, total = $lengthFromPathEnd mm.");

                // Check for beginning of path
                if($input[$filePos-1][0] == ";") {
                    $foundPathStart = true;
                    debug("- Found path start. Total path length = $lengthFromPathEnd.");
                }

                if($done == false && $endXY != false) {	// We're not done, and have a valid line segment

                    if($lengthFromPathEnd > $coastDistance && $foundPathStart) {

                        debug("/!\\ Segment exceeds coast length. dist=$dist");

                        // Chop the segment into two, the first with extrusion and the second without.
                        $splitDistFromStart = $lengthFromPathEnd - $coastDistance;
                        debug("! We will be splitting this line $splitDistFromStart mm from its starting point.");

                        $xSlope = $endXY['x'] - $startXY['x'];
                        $ySlope = $endXY['y'] - $startXY['y'];
                        $distRatio = $splitDistFromStart / $dist;
                        debug("! The distance ratio (desired distance / total) is $distRatio.");
                        
                        // Perform linear interpolation
                        $splitPoint = array(
                            'x' => (1-$distRatio) * $startXY['x'] + ($distRatio * $endXY['x']),
                            'y' => (1-$distRatio) * $startXY['y'] + ($distRatio * $endXY['y'])
                        );
                        debug("splitPoint at <${splitPoint['x']}, ${splitPoint['y']}>");
                        debug("The two lines involved in this segment are:");
                        debug("\t" . $input[$filePos - 1]);
                        debug("\t" . $input[$filePos]);
                        
                        $seg1 = gcodeToXYZEF($input[$filePos - 1]);
                        $seg2 = gcodeToXYZEF($input[$filePos]);
                        debug(" ");
                        debug("Seg1: <${seg1['x']}, ${seg1['y']}>, E=${seg1['e']}, F=${seg1['f']}");
                        debug("Seg2: <${seg2['x']}, ${seg2['y']}>, E=${seg2['e']}, F=${seg2['f']}");
                        
                        // Save feedrate
                        $feedrate = $seg2['f'];
                        
                        // Figure out how much to extrude
                        $eDist = ($seg2['e'] - $seg1['e']);
                        $splitE = $seg1['e'] + ($eDist * $distRatio);
                        debug("eDist: $eDist / splitE: $splitE");
                        
                        // Build the two new lines we'll insert, including Z coordinates if supplied
                        $nl1 = sprintf("G1 X%1.4f Y%1.4f E%1.4f", $splitPoint['x'], $splitPoint['y'], $splitE);
                        if($seg1['z'] != null) {
                            $nl1 .= " Z" . $seg1['z'];
                        }
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
                        $nl2 .= " ; Begin coast ($coastDistance mm)";
                        
                        debug(" ");
                        debug("New line 1: ${nl1}");
                        debug("New line 2: ${nl2}");

                        // Replace existing line with 1st new line
                        $input[$filePos] = $nl1;
                        
                        // Insert the 2nd new line after the 1st, but only if it isn't too short
                        $nlDist = distance2D($splitPoint, $endXY);
                        debug("!!! Distance between the two new lines is $nlDist.");

                        if($nlDist > 0.01) {
                            array_splice($input, $filePos + 1, 0, $nl2);
                            $filePos++;	// Skip ahead one more (because we inserted a line) before stripping E moves
                            $x++;		// Increment input file pointer so we don't re-process the same line
                        } else {
                            debug("!!! Skipping insertion of second line because the distance is less than 10 microns.");
                            $input[$filePos + 1] .= " ; Beginning coast at segment boundary ($coastDistance mm)";
                        }

                        // Update stats
                        if($isPrimePillar) {
                            $stats['prime']++;
                        } else {
                            $stats['regular']++;
                        }

                        // Strip extruder moves from remaining lines in this path
                        for($y = $filePos + 1; $y < $x; $y++) {
                            debug("- Stripping E-axis moves from line: '${input[$y]}'");
                            $input[$y] = stripE($input[$y]);
                            debug("                            Result: '${input[$y]}'");
                        }

                        debug("\n/!\\ Done with this path.");
                        debug(" ");
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
    debug("This is the master process.");

    $nLines = count($input);
    $chunkSize = intval($nLines / $processes);
    debug("Chunk size: $chunkSize");

    // Make sure we don't get a destring command in the middle of a chunk boundary
    $chunkStart = $chunkEnd = 0;
    $workerProcesses = array();
    for($x = 1; $x <= $processes; $x++) {
    
        $chunkEnd = intval($x * $chunkSize);
        debug("Chunk $x starts at line $chunkStart");
        
        // Scan until after we find a destring
        $done = false;
        $foundDestring = false;
        for($y = $chunkEnd; $y < $nLines && !$done; $y++) {

            // Check for destring
            if(strstr($input[$y], $triggers['destring'])) {
                debug("Found destring at line $y");
                $foundDestring = true;
            }
            
            if($foundDestring && trim($input[$y]) == ";") {
                debug("Found terminal comment after destring at line $y. This is the chunk end.");
                $chunkEnd = $y;
                $done = true;
            }
        
        }

        // Still need to write the final chunk here.
        if($x == $processes) {
            debug("Final process, writing to EOF.");
            $chunkEnd = $nLines - 1;	// Minus one because first key is 0, not 1.            
        }
        
        debug("Chunk ends at $chunkEnd. Writing KISSCoast_wd_$processMasterID/$x.out.");
        $chunkFileName = "$processDir/$x.in";
        if(!$chunkFile = fopen($chunkFileName, 'w')) {
            echo "ERROR: Couldn't open chunk file for writing.\n";
            exit(11);
        }
        debug("Writing lines from $chunkStart to $chunkEnd.");
        for($y = $chunkStart; $y <= $chunkEnd; $y++) {
            fwrite($chunkFile, $input[$y] . "\n");
        }
        fclose($chunkFile);
        
        $chunkStart = $chunkEnd + 1;
        
        debug("Writing complete. Opening process...");

        $descriptorSpec = array(
           0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
           1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
           2 => array("file", "$processDir/error-output-$x.txt", "a") // stderr is a file to write to
        );

        $cwd = getcwd();
        $env = null; //array('some_option' => 'aeiou');
        $cmd = "./KISSCoast.php --coast=${options['coast']} --primePillarCoast=${options['primePillarCoast']} " .
               "--minExtrusionLength=${options['minExtrusionLength']} " .
               "--processMasterID=$processMasterID --processID=$x";
        debug("Worker $x cmd: $cmd");
        debug();

        // Open the actual process
        $workerProcesses[$x]['proc'] = proc_open($cmd, $descriptorSpec, $pipes, $cwd, $env);

        // Set pipe to non-blocking and save for later
        stream_set_blocking($pipes[1], 0);
        $workerProcesses[$x]['pipes'] = $pipes;

    }

    // Wait for worker processes to finish
    debug("Waiting for worker processes to finish...");
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
				    debug("Got FEOF from process $x.");
				    proc_close($workerProcesses[$x]['proc']);
				}
			}
		}
		if($runningProcs == 0) {
		    debug("No running processes left!");
		    $done = true;
		}
	}

	// Coalesce output files into the input array
	for($x = 1; $x <= $processes; $x++) {
	    debug("Writing intermediate output to final output (#$x)");
	    $input = file_get_contents("$processDir/$x.out");
	    fwrite($output, $input);
	    
	    // Delete intermediate files
	    if(!$keepWorkDir) {
    	    unlink("$processDir/$x.in");
    	    unlink("$processDir/$x.out");
    	    unlink("$processDir/error-output-$x.txt");
        }
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
if($processes > 1 && !$keepWorkDir) {
    if(!rmdir($processDir)) {
        echo "NOTICE: Unable to remove work directory '$processDir'.\n";
    }
}

// Close output stream
fclose($output);

debug();
debug("Done.");
debug();

?>