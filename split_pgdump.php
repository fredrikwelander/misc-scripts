#!/usr/bin/php -q
<?php

/*
| Yet another schema dump splitter.
| Splits a postgresql schema dump into separate files.
| The script tries to bundle rules, indexes, sequences and such within 
| the parent object file.
|
*/


$conf = [
    "ignoretypes" => [ "ACL", "SEQUENCE", "SEQUENCE OWNED BY", "DEFAULT", "DEFAULT ACL" ],
    "savetypes" => ["TABLE", "VIEW", "FUNCTION", "EXTENSION" ],
];

if (empty($argv[1]) || empty($argv[2])) {
    exit("USAGE: split_pgdump.php SCHEMA_FILE OUTPUT_DIR\n");
}

$dumpfile = realpath($argv[1]);
$outdir = realpath($argv[2]);

if (empty($outdir)) {
    exit("ERROR: Bad output OUTPUT_DIR\n");
} else if (empty($dumpfile)) {
    exit("ERROR: SCHEMA_FILE not found\n");
}

echo "Splitting " . $dumpfile . " to " . $outdir . "/";
echo "\n";

$contents = file_get_contents($dumpfile);
$parts = preg_split('/(-- Name: [a-zA-Z0-9 "\(\),:;_-]+)/', $contents, 0, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);

$splitted = [];


foreach($parts as $key => $part) {
    // preg_split will put delimiters in odd keys, so:
    if ($key % 2 == 1) {
        // Parse header into assoc array
        $h = substr($parts[$key], 3);
        $h = str_replace('"','',$h);
        $h = str_replace('; ','", "',$h);
        $h = '{"' . str_replace(': ','": "',$h) . '"}';
        $h = (array) json_decode($h);
        $name = $h["Name"];

        // Parse type suppressing errors
        $type = @$h["Type"];

        if (!in_array($type, $conf["ignoretypes"])) {
           
            // Parse name
            $name = explode(".", $name)[0];
            $name = explode("(", $name)[0];
            $name = str_replace('TABLE ','',$name);
            $name = str_replace('VIEW ','',$name);
            $name = str_replace('COMMENT ON COLUMN ','',$name);
            $name = str_replace('COLUMN ','',$name);
            $name = str_replace('EXTENSION ','',$name);

            // Parse special types to find their parent object name
            if ($type == "INDEX") {
                $name = strstr(substr(strstr($parts[$key+1], "ON"), 3), " ", true);
            } else if ($type == "CONSTRAINT") {
                $name = strstr(substr(strstr($parts[$key+1], "ALTER TABLE ONLY"), 17), "\n", true);
            }  else if ($type == "RULE") {
                $name = strstr(substr(strstr($parts[$key+1], "TO"), 3), " ", true);
            }

            // Put content into correct key
            if (empty($splitted[$name])) {
                $splitted[$name] = ["body" => "", "type" => ""];
                $splitted[$name]["body"] = "-- \n" . $parts[$key];
                $addcontent = $parts[$key+1];
            } else {
                $addcontent = "-- \n" . $parts[$key]. $parts[$key+1];
            }
            $splitted[$name]["body"] .= substr($addcontent, 0, -3);

            // Only add defined content types
            if (in_array($type, $conf["savetypes"])) {
                $splitted[$name]["type"] = $type;
            }
        }
    }
}

// Create files
foreach($splitted as $key => $split) {
    $filename = $outdir . "/" . str_replace(" ", "_", $split["type"] . "_" . $key . ".sql");
    $contents = $split["body"];
    file_put_contents($filename, $contents);
}

exit("Dump splitted into " . count($splitted) . " files.\n");


