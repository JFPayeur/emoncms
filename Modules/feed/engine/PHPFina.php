<?php

// This timeseries engine implements:
// Fixed Interval No Averaging

class PHPFina
{
    private $dir = "/var/lib/phpfina/";
    private $log;
    private $writebuffer = array();

    /**
     * Constructor.
     *
     * @api
    */
    public function __construct($settings)
    {
        if (isset($settings['datadir'])) $this->dir = $settings['datadir'];
        $this->log = new EmonLogger(__FILE__);
    }

// #### \/ Below are required methods

    /**
     * Create feed
     *
     * @param integer $feedid The id of the feed to be created
     * @param array $options for the engine
    */
    public function create($feedid,$options)
    {
        $feedid = (int)$feedid;
        $interval = (int) $options['interval'];
        if ($interval<5) $interval = 5;
        
        // Check to ensure we dont overwrite an existing feed
        if (!$meta = $this->get_meta($feedid))
        {
            // Set initial feed meta data
            $meta = new stdClass();
            $meta->interval = $interval;
            $meta->start_time = 0;
            $meta->npoints = 0;

            // Save meta data
            $msg=$this->create_meta($feedid,$meta);
            if ($msg !== true) {
                return $msg;
            }

            $fh = @fopen($this->dir.$feedid.".dat", 'c+');
            if (!$fh) {
                $msg = "could not create meta data file " . error_get_last()['message'];
                $this->log->error("create() ".$msg);
                return $msg;
            }
            fclose($fh);
            $this->log->info("create() feedid=$feedid");
        }

        $feedname = "$feedid.meta";
        if (file_exists($this->dir.$feedname)) {
            return true;
        } else {
            $msg = "create failed, could not find meta data file '".$this->dir.$feedname."'";
            $this->log->error("create() ".$msg);
            return $msg;
        }
    }

    /**
     * Delete feed
     *
     * @param integer $feedid The id of the feed to be created
    */
    public function delete($feedid)
    {
        $feedid = (int)$feedid;
        $meta = $this->get_meta($feedid);
        if (!$meta) return false;
        unlink($this->dir.$feedid.".meta");
        unlink($this->dir.$feedid.".dat");
        if (isset($metadata_cache[$feedid])) { unset($metadata_cache[$feedid]); } // Clear static cache
    }

    /**
     * Gets engine metadata
     *
     * @param integer $feedid The id of the feed to be created
    */
    public function get_meta($feedid)
    {
        $feedid = (int) $feedid;
        $feedname = "$feedid.meta";

        if (!file_exists($this->dir.$feedname)) {
            $this->log->warn("get_meta() meta file does not exist '".$this->dir.$feedname."'");
            return false;
        }

        static $metadata_cache = array(); // Array to hold the cache
        if (isset($metadata_cache[$feedid])) {
            return $metadata_cache[$feedid]; // Retrieve from static cache
        } else {
            // Open and read meta data file
            // The start_time and interval are saved as two consecutive unsigned integers
            $meta = new stdClass();
            $metafile = fopen($this->dir.$feedname, 'rb');
            fseek($metafile,8);
            $tmp = unpack("I",fread($metafile,4)); 
            $meta->interval = $tmp[1];
            $tmp = unpack("I",fread($metafile,4)); 
            $meta->start_time = $tmp[1];
            fclose($metafile);

            $metadata_cache[$feedid] = $meta; // Cache it
            return $meta;
        }
    }

    /**
     * Returns engine occupied size in bytes
     *
     * @param integer $feedid The id of the feed to be created
    */
    public function get_feed_size($feedid)
    {
        $meta = $this->get_meta($feedid);
        if (!$meta) return false;
        return (16 + filesize($this->dir.$feedid.".dat"));
    }

    /**
     * Adds a data point to the feed
     *
     * @param integer $feedid The id of the feed to add to
     * @param integer $time The unix timestamp of the data point, in seconds
     * @param float $value The value of the data point
     * @param array $arg optional padding mode argument
    */
    public function post($id,$timestamp,$value,$padding_mode=null)
    {
        $this->log->info("post() id=$id timestamp=$timestamp value=$value padding=$padding_mode");
        
        $id = (int) $id;
        $timestamp = (int) $timestamp;
        $value = (float) $value;
        
        $now = time();
        $start = $now-(3600*24*365*5); // 5 years in past
        $end = $now+(3600*48);         // 48 hours in future
        
        if ($timestamp<$start || $timestamp>$end) {
            $this->log->warn("post() timestamp out of range");
            return false;
        }
        
        // If meta data file does not exist then exit
        if (!$meta = $this->get_meta($id)) {
            $this->log->warn("post() failed to fetch meta id=$id");
            return false;
        }
        $meta->npoints = $this->get_npoints($id);
        
        // Calculate interval that this datapoint belongs too
        $timestamp = floor($timestamp / $meta->interval) * $meta->interval;
        
        // If this is a new feed (npoints == 0) then set the start time to the current datapoint
        if ($meta->npoints == 0 && $meta->start_time==0) {
            $meta->start_time = $timestamp;
            $this->create_meta($id,$meta);
        }

        if ($timestamp < $meta->start_time) {
            $this->log->warn("post() timestamp older than feed start time id=$id");
            return false; // in the past
        }

        // Calculate position in base data file of datapoint
        $pos = floor(($timestamp - $meta->start_time) / $meta->interval);

        $last_pos = $meta->npoints - 1;


        $fh = fopen($this->dir.$id.".dat", 'c+');
        if (!$fh) {
            $this->log->warn("post() could not open data file id=$id");
            return false;
        }
        
        // Write padding
        $padding = ($pos - $last_pos)-1;
        
        // Max padding = 1 million datapoints ~4mb gap of 115 days at 10s
        $maxpadding = 1000000;
        
        if ($padding>$maxpadding) {
            $this->log->warn("post() padding max block size exeeded id=$id, $padding dp");
            return false;
        }
        
        if ($padding>0) {
            $padding_value = NAN;
            
            if ($last_pos>=0 && $padding_mode!=null) {
                fseek($fh,$last_pos*4);
                $val = unpack("f",fread($fh,4));
                $last_val = (float) $val[1];
                
                $padding_value = $last_val;
                $div = ($value - $last_val) / ($padding+1);
            }
            
            $buffer = "";
            for ($i=0; $i<$padding; $i++) {
                if ($padding_mode=="join") $padding_value += $div;
                $buffer .= pack("f",$padding_value);
                //$this->log->info("post() ##### paddings ". ((4*$meta->npoints) + (4*$i)) ." $i $padding_mode $padding_value");
            }
            fseek($fh,4*$meta->npoints);
            fwrite($fh,$buffer);
            
        }
        
        // Write new datapoint
        fseek($fh,4*$pos);
        if (!is_nan($value)) fwrite($fh,pack("f",$value)); else fwrite($fh,pack("f",NAN));
        //$this->log->info("post() ##### value    ". (4*$pos)." $value");
        
        // Close file
        fclose($fh);
        
        return $value;
    }
    
    /**
     * Updates a data point in the feed
     *
     * @param integer $feedid The id of the feed to add to
     * @param integer $time The unix timestamp of the data point, in seconds
     * @param float $value The value of the data point
    */
    public function update($feedid,$timestamp,$value)
    {
        if (isset($this->writebuffer[$feedid]) && strlen($this->writebuffer[$feedid]) > 0) {
            $this->post_bulk_save();// if data on buffer, flush buffer now, then update it
            $this->log->info("update() $feedid with buffer");
        }
        return $this->post($feedid,$timestamp,$value);  //post can also update 
    }

    /**
     * Get array with last time and value from a feed
     *
     * @param integer $feedid The id of the feed
    */
    public function lastvalue($feedid)
    {
        $feedid = (int)$feedid;
        $this->log->info("lastvalue() $feedid");
        
        // If meta data file does not exist exit
        if (!$meta = $this->get_meta($feedid)) return false;
        $meta->npoints = $this->get_npoints($feedid);

        if ($meta->npoints>0) {
            $fh = fopen($this->dir.$feedid.".dat", 'rb');
            $size = filesize($this->dir.$feedid.".dat");
            fseek($fh,$size-4);
             $d = fread($fh,4);
            fclose($fh);

            $val = unpack("f",$d);
            $time = $meta->start_time + ($meta->interval * $meta->npoints);
            return array('time'=>$time, 'value'=>$val[1]);
        }
        return false;
    }

    /**
     * Return the data for the given timerange
     *
     * @param integer $feedid The id of the feed to fetch from
     * @param integer $start The unix timestamp in ms of the start of the data range
     * @param integer $end The unix timestamp in ms of the end of the data range
     * @param integer $interval The number os seconds for each data point to return (used by some engines)
     * @param integer $skipmissing Skip null values from returned data (used by some engines)
     * @param integer $limitinterval Limit datapoints returned to this value (used by some engines)
    */
    public function get_data($name,$start,$end,$interval,$skipmissing,$limitinterval)
    {
        $start = intval($start/1000);
        $end = intval($end/1000);
        $interval= (int) $interval;
        

        // Minimum interval
        if ($interval<1) $interval = 1;
        // Maximum request size
        $req_dp = round(($end-$start) / $interval);
        if ($req_dp>3000) return array('success'=>false, 'message'=>"Request datapoint limit reached (3000), increase request interval or time range, requested datapoints = $req_dp");
        
        // If meta data file does not exist exit
        if (!$meta = $this->get_meta($name)) return array('success'=>false, 'message'=>"Error reading meta data feedid=$name");
        $meta->npoints = $this->get_npoints($name);
        
        if ($limitinterval && $interval<$meta->interval) $interval = $meta->interval; 

        $this->log->info("get_data() feed=$name st=$start end=$end int=$interval sk=$skipmissing lm=$limitinterval pts=$meta->npoints st=$meta->start_time");

        $data = array();
        $time = 0; $i = 0;
        $numdp = 0;
        // The datapoints are selected within a loop that runs until we reach a
        // datapoint that is beyond the end of our query range
        $fh = fopen($this->dir.$name.".dat", 'rb');
        while($time<=$end)
        {
            $time = $start + ($interval * $i);
            $pos = round(($time - $meta->start_time) / $meta->interval);
            $value = null;

            if ($pos>=0 && $pos < $meta->npoints)
            {
                // read from the file
                fseek($fh,$pos*4);
                $val = unpack("f",fread($fh,4));

                // add to the data array if its not a nan value
                if (!is_nan($val[1])) {
                    $value = $val[1];
                } else {
                    $value = null;
                }
                //$this->log->info("get_data() ". ($pos*4) ." time=$time value=$value"); 
            }
            
            if ($value!==null || $skipmissing===0) {
                $data[] = array($time*1000,$value);
            }

            $i++;
        }
        return $data;
    }

    public function export($id,$start)
    {
        $id = (int) $id;
        $start = (int) $start;
        
        $feedname = $id.".dat";
        
        // If meta data file does not exist exit
        if (!$meta = $this->get_meta($id)) {
            $this->log->warn("PHPFina:post failed to fetch meta id=$id");
            return false;
        }

        $meta->npoints = $this->get_npoints($id);
        
        // There is no need for the browser to cache the output
        header("Cache-Control: no-cache, no-store, must-revalidate");

        // Tell the browser to handle output as a csv file to be downloaded
        header('Content-Description: File Transfer');
        header("Content-type: application/octet-stream");
        header("Content-Disposition: attachment; filename={$feedname}");

        header("Expires: 0");
        header("Pragma: no-cache");

        // Write to output stream
        $fh = @fopen( 'php://output', 'w' );
        
        $primary = fopen($this->dir.$feedname, 'rb');
        $primarysize = filesize($this->dir.$feedname);
        
        $localsize = $start;
        $localsize = intval($localsize / 4) * 4;
        if ($localsize<0) $localsize = 0;

        // Get the first point which will be updated rather than appended
        if ($localsize>=4) $localsize = $localsize - 4;
        
        fseek($primary,$localsize);
        $left_to_read = $primarysize - $localsize;
        if ($left_to_read>0){
            do
            {
                if ($left_to_read>8192) $readsize = 8192; else $readsize = $left_to_read;
                $left_to_read -= $readsize;

                $data = fread($primary,$readsize);
                fwrite($fh,$data);
            }
            while ($left_to_read>0);
        }
        fclose($primary);
        fclose($fh);
        exit;

    }

    public function csv_export($feedid,$start,$end,$outinterval)
    {
        global $csv_decimal_places;
        global $csv_decimal_place_separator;
        global $csv_field_separator;
        $feedid = intval($feedid);
        $start = intval($start);
        $end = intval($end);
        $outinterval= (int) $outinterval;

        // If meta data file does not exist exit
        if (!$meta = $this->get_meta($feedid)) return false;

        $meta->npoints = $this->get_npoints($feedid);
        
        if ($outinterval<$meta->interval) $outinterval = $meta->interval;
        $dp = ceil(($end - $start) / $outinterval);
        $end = $start + ($dp * $outinterval);
        
        // $dpratio = $outinterval / $meta->interval;
        if ($dp<1) return false;

        // The number of datapoints in the query range:
        $dp_in_range = ($end - $start) / $meta->interval;

        // Divided by the number we need gives the number of datapoints to skip
        // i.e if we want 1000 datapoints out of 100,000 then we need to get one
        // datapoints every 100 datapoints.
        $skipsize = round($dp_in_range / $dp);
        if ($skipsize<1) $skipsize = 1;

        // Calculate the starting datapoint position in the timestore file
        if ($start>$meta->start_time){
            $startpos = ceil(($start - $meta->start_time) / $meta->interval);
        } else {
            $start = ceil($meta->start_time / $outinterval) * $outinterval;
            $startpos = ceil(($start - $meta->start_time) / $meta->interval);
        }

        $data = array();
        $time = 0; $i = 0;
        
        // There is no need for the browser to cache the output
        header("Cache-Control: no-cache, no-store, must-revalidate");

        // Tell the browser to handle output as a csv file to be downloaded
        header('Content-Description: File Transfer');
        header("Content-type: application/octet-stream");
        $filename = $feedid.".csv";
        header("Content-Disposition: attachment; filename={$filename}");

        header("Expires: 0");
        header("Pragma: no-cache");

        // Write to output stream
        $exportfh = @fopen( 'php://output', 'w' );


        // The datapoints are selected within a loop that runs until we reach a
        // datapoint that is beyond the end of our query range
        $fh = fopen($this->dir.$feedid.".dat", 'rb');
        while($time<=$end)
        {
            // $position steps forward by skipsize every loop
            $pos = ($startpos + ($i * $skipsize));

            // Exit the loop if the position is beyond the end of the file
            if ($pos > $meta->npoints-1) break;

            // read from the file
            fseek($fh,$pos*4);
            $val = unpack("f",fread($fh,4));

            // calculate the datapoint time
            $time = $meta->start_time + $pos * $meta->interval;

            // add to the data array if its not a nan value
            if (!is_nan($val[1])) fwrite($exportfh, $time.$csv_field_separator.number_format($val[1],$csv_decimal_places,$csv_decimal_place_separator,'')."\n");

            $i++;
        }
        fclose($exportfh);
        exit;
    }

// #### /\ Above are required methods


// #### \/ Below are buffer write methods

    // Insert data in post write buffer, parameters like post()
    public function post_bulk_prepare($feedid,$timestamp,$value,$padding_mode)
    {   
        $feedid = (int) $feedid;
        $timestamp = (int) $timestamp;
        $value = (float) $value;
        $this->log->info("post_bulk_prepare() feedid=$feedid timestamp=$timestamp value=$value");
        
        // Check timestamp range
        $now = time();
        $start = $now-(3600*24*365*5); // 5 years in past
        $end = $now+(3600*48);         // 48 hours in future
        if ($timestamp<$start || $timestamp>$end) {
            $this->log->warn("post_bulk_prepare() timestamp out of range");
            return false;
        }

        // Check meta data file exists
        if (!$meta = $this->get_meta($feedid)) {
            $this->log->warn("post_bulk_prepare() failed to fetch meta feedid=$feedid");
            return false;
        }

        $meta->npoints = $this->get_npoints($feedid);

        // Calculate interval that this datapoint belongs too
        $timestamp = floor($timestamp / $meta->interval) * $meta->interval;

        // If this is a new feed (npoints == 0) then set the start time to the current datapoint
         if ($meta->npoints == 0 && $meta->start_time==0) {
            $meta->start_time = $timestamp;
            $this->create_meta($feedid,$meta);
        }

        if ($timestamp < $meta->start_time) {
            $this->log->warn("post_bulk_prepare() timestamp=$timestamp older than feed starttime=$meta->start_time feedid=$feedid");
            return false; // in the past
        }

        // Calculate position in base data file of datapoint
        $pos = floor(($timestamp - $meta->start_time) / $meta->interval);
        $last_pos = $meta->npoints - 1;
        
        $this->log->info("post_bulk_prepare() pos=$pos last_pos=$last_pos timestampinterval=$timestamp");
        
        if ($pos>$last_pos) {
            $npadding = ($pos - $last_pos)-1;
            
            if (!isset($this->writebuffer[$feedid])) {
                $this->writebuffer[$feedid] = "";    
            }
            
            if ($npadding>0) {
                $padding_value = NAN;
                if ($padding_mode!=null) {
                    static $lastvalue_static_cache = array(); // Array to hold the cache
                    if (!isset($lastvalue_static_cache[$feedid])) { // Not set, cache it from file data
                        $lastvalue_static_cache[$feedid] = $this->lastvalue($feedid)['value'];
                    }
                    $div = ($value - $lastvalue_static_cache[$feedid]) / ($npadding+1);
                    $padding_value = $lastvalue_static_cache[$feedid];
                    $lastvalue_static_cache[$feedid] = $value; // Set static cache last value
                }
                
                for ($n=0; $n<$npadding; $n++)
                {
                    if ($padding_mode=="join") $padding_value += $div; 
                    $this->writebuffer[$feedid] .= pack("f",$padding_value);
                    //$this->log->info("post_bulk_prepare() ##### paddings ". ((4*$meta->npoints) + (4*$n)) ." $n $padding_mode $padding_value");
                }
            }
            
            $this->writebuffer[$feedid] .= pack("f",$value);
            //$this->log->info("post_bulk_prepare() ##### value saved $value");
        } else {
            // if data is in past, its not supported, could call update here to fix on file before continuing
            // but really this should not happen for past data has process_feed_buffer uses update for that.
            // so this must be data posted in less time of the feed interval and can be ignored
            $this->log->warn("post_bulk_prepare() data in past or before next interval, nothing saved. Posting too fast? slot=$meta->interval feedid=$feedid timestamp=$timestamp pos=$pos last_pos=$last_pos value=$value");
        }
        
        return $value;
    }

    // Saves post buffer to engine in bulk
    // Writing data in larger blocks saves reduces disk write load
    public function post_bulk_save()
    {
        $byteswritten = 0;
        foreach ($this->writebuffer as $feedid=>$data) {
            // Auto-correction if something happens to the datafile, it gets partitally written to
            // this will correct the file size to always be an integer number of 4 bytes.
            $filename = $this->dir.$feedid.".dat";
            clearstatcache($filename);
            if (@filesize($filename)%4 != 0) {
                $npoints = floor(filesize($filename)/4.0);
                $fh = fopen($filename,"c");
                if (!$fh) {
                    $this->log->warn("post_bulk_save() could not open data file '$filename'");
                    return false;
                }

                fseek($fh,$npoints*4.0);
                fwrite($fh,$data);
                fclose($fh);
                print "PHPFINA: FIXED DATAFILE WITH INCORRECT LENGHT '$filename'\n";
                $this->log->warn("post_bulk_save() FIXED DATAFILE WITH INCORRECT LENGHT '$filename'");
            }
            else
            {
                $fh = fopen($filename,"ab");
                if (!$fh) {
                    $this->log->warn("save() could not open data file '$filename'");
                    return false;
                }
                fwrite($fh,$data);
                fclose($fh);
            }
            
            $byteswritten += strlen($data);
        }
        
        $this->writebuffer = array(); // clear buffer
        
        return $byteswritten;
    }



// #### \/ Below engine specific methods


// #### \/ Bellow are engine private methods    
    
    private function create_meta($feedid, $meta)
    {
        $feedname = $feedid . ".meta";
        $metafile = @fopen($this->dir.$feedname, 'wb');
        
        if (!$metafile) {
            $msg = "could not write meta data file " . error_get_last()['message'];
            $this->log->error("create_meta() ".$msg);
            return $msg;
        }
        if (!flock($metafile, LOCK_EX)) {
            $msg = "meta data file '".$this->dir.$feedname."' is locked by another process";
            $this->log->error("create_meta() ".$msg);
            fclose($metafile);
            return $msg;
        }
        fwrite($metafile,pack("I",0));
        fwrite($metafile,pack("I",0)); 
        fwrite($metafile,pack("I",$meta->interval));
        fwrite($metafile,pack("I",$meta->start_time)); 
        fclose($metafile);
        return true;
    }
    
    private function get_npoints($feedid)
    {
        $bytesize = 0;
        
        if (file_exists($this->dir.$feedid.".dat")) {
            clearstatcache($this->dir.$feedid.".dat");
            $bytesize += filesize($this->dir.$feedid.".dat");
        }
            
        if (isset($this->writebuffer[$feedid]))
            $bytesize += strlen($this->writebuffer[$feedid]);
            
        return floor($bytesize / 4.0);
    }   

}
