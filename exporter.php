<?php
/**
 * NUT Prometheus Exporter - Multi-server / Multi-UPS / Built-in HTTP
 * -------------------------------------------------------------------
 * Pure PHP Prometheus exporter for Network UPS Tools (NUT).
 * Designed to be portable, efficient, and dependency-free.
 *
 * Features:
 * - Connects to one or more NUT servers (over TCP)
 * - Supports multiple UPS definitions per server
 * - Outputs metrics in Prometheus exposition format
 * - Optional filtering, labeling, caching, and metric path control
 * - Built-in HTTP support using PHP's development server
 *
 * @author
 * @version 1.0
 */

define('CONFIG_FILE', __DIR__ . '/config.json');
define('CACHE_FILE', __DIR__ . '/cache/metrics.cache');
define('LOG_FILE', __DIR__ . '/logs/exporter.log');

/**
 * Log error messages to a file.
 *
 * @param string $message The message to log
 */
function log_error(string $message): void {
    error_log("[" . date("Y-m-d H:i:s") . "] $message\n", 3, LOG_FILE);
}

/**
 * Load and validate the JSON configuration file.
 *
 * @return array The parsed configuration array
 */
function load_config(): array {
    if (!file_exists(CONFIG_FILE)) {
        log_error("Config file missing.");
        http_response_code(500);
        exit("Configuration error: config.json is missing.");
    }
    log_error("Configs file found");
    $config = json_decode(file_get_contents(CONFIG_FILE), true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($config)) {
        log_error("Malformed config file.");
        http_response_code(500);
        exit("Configuration error: config.json is malformed.");
    }
log_error("Configs file loaded");
    if (empty($config['servers']) || !is_array($config['servers'])) {
        log_error("No servers defined in config.");
        http_response_code(500);
        exit("Configuration error: 'servers' must be a non-empty array.");
    }
log_error("Configs file processed");
    return $config;
}

/**
 * Open a TCP socket and authenticate with the NUT server.
 *
 * @param string $host NUT server hostname
 * @param int $port NUT server port
 * @param string|null $username Optional NUT username
 * @param string|null $password Optional NUT password
 * @param int $timeout Timeout in seconds
 * @return resource TCP socket connection
 * @throws Exception If connection or authentication fails
 */
function connect_to_nut(string $host, int $port, ?string $username = null, ?string $password = null, int $timeout = 3) {
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!$fp) {
        throw new Exception("Unable to connect to $host:$port - $errstr ($errno)");
    }

    stream_set_timeout($fp, 2);

    // Perform authentication if username and password are provided
    if ($username && $password) {
        fwrite($fp, "USERNAME $username\n");
        $resp = trim(fgets($fp));
        if (str_starts_with($resp, "ERR")) {
            throw new Exception("Auth error (username): $resp");
        }

        fwrite($fp, "PASSWORD $password\n");
        $resp = trim(fgets($fp));
        if (str_starts_with($resp, "ERR")) {
            throw new Exception("Auth error (password): $resp");
        }
    }

    return $fp;
}

/**
 * Get all available variables from a UPS.
 *
 * @param resource $fp Open socket to NUT server
 * @param string $upsname UPS name
 * @return array Associative array of variables
 * @throws Exception If UPS is not found
 */
function get_all_vars($fp, string $upsname): array {
    fwrite($fp, "LIST VAR $upsname\n");
    $vars = [];
    log_error($upsname . "var linees: ");
    while (($line = fgets($fp)) !== false) {
        $line = trim($line);    
        log_error($line);
        if (str_starts_with($line, "ERR UNKNOWN-UPS")) {
            throw new Exception("UPS '$upsname' not found.");
        }
        if ($line === "END LIST VAR") break;

        if (preg_match('/^VAR ' . preg_quote($upsname, '/') . ' (\S+) "(.*?)"$/', $line, $matches)) {
            log_error("MATCHED: " . $matches[1] . " => " . $matches[2]);
            $vars[$matches[1]] = $matches[2];
        }

    }

    return $vars;
}

/**
 * Filter UPS variables based on config filters.
 *
 * @param array $vars All UPS variables
 * @param array $filters Optional list of filters
 * @return array Filtered variables
 */
function get_filtered_vars(array $vars, array $filters = []): array {
    return empty($filters)
        ? $vars
        : array_filter($vars, fn($key) => in_array($key, $filters), ARRAY_FILTER_USE_KEY);
}

/**
 * Convert a variable name to a Prometheus-safe metric name.
 *
 * @param string $name NUT variable name
 * @return string Prometheus metric name
 */
function format_prometheus_name(string $name): string {
    return "nut_" . str_replace(['.', '-'], '_', strtolower($name));
}

/**
 * Generate Prometheus labels for a metric.
 *
 * @param string $upsname UPS name
 * @param string $host NUT server hostname
 * @param array $custom Custom labels
 * @return array Merged label set
 */
function format_labels(string $upsname, string $host, array $custom = []): array {
    return array_merge([
        'ups' => $upsname,
        'server' => $host
    ], $custom);
}

/**
 * Fetch metrics from all UPSes across all configured NUT servers.
 *
 * @param array $config Exporter configuration
 * @return array Prometheus metrics
 */
function get_metrics(array $config): array {
    $metrics = [];
    $filters = $config['filter_metrics'] ?? [];

    foreach ($config['servers'] as $server) {
        $host = $server['host'] ?? 'localhost';
        $port = $server['port'] ?? 3493;
        $upses = $server['upses'] ?? [];

        if (!is_array($upses) || empty($upses)) {
            log_error("No UPSes defined under server $host:$port");
            continue;
        }

        try {
            $username = $server['username'] ?? null;
            $password = $server['password'] ?? null;
            $fp = connect_to_nut($host, $port, $username, $password);
            log_error("Connected to server $host:$port");

            foreach ($upses as $ups) {
                if (empty($ups['name'])) {
                    log_error("Missing UPS name in server $host:$port");
                    continue;
                }

                $upsname = $ups['name'];
                $customLabels = $ups['labels'] ?? [];

                try {
                    $vars = get_all_vars($fp, $upsname);
                } catch (Exception $e) {
                    log_error("[$host:$port/$upsname] " . $e->getMessage());
                    continue;
                }
                log_error("vars len is ". count($vars) ."");

                log_error("connected to ups [$host:$port/$upsname] ");

                $filtered = get_filtered_vars($vars, $filters);
                log_error("Got filters");

                $renameMap = $config['rename_vars'] ?? [];


                foreach ($filtered as $var => $val) {
                        $labels = format_labels($upsname, $host, $customLabels);
                        $renamed = rename_var($var, $renameMap);
                        $metric = format_prometheus_name($renamed);
                        if(is_numeric($val)) {
                            $metrics[$metric][] = [
                                'value' => $val,
                                'labels' => $labels,
                                'help' => ucfirst(str_replace('.', ' ', $var)),
                        ];
                } else {
                    $metrics[$metric][] = [
                        'value'=> 1,
                        'labels' => array_merge($labels, [
                            'key' => $var,
                            'value' => $val
                        ]),
                        'help' => 'String-valued NUT metrics'
                    ];
                }
            }
                log_error("added vars to metrics for ups [$host:$port/$upsname] ");

            }

            fclose($fp);
        } catch (Exception $e) {
            log_error("[$host:$port] Connection failed: " . $e->getMessage());
        }
    }
    log_error("returing Metrics for [$host:$port] with count " . count($metrics));
    return $metrics;
}

/**
 * Rename a NUT variable using config mapping if applicable.
 *
 * @param string $original Original variable name (e.g. "ups.status")
 * @param array $renameMap Map of old => new variable names
 * @return string Final metric name
 */
function rename_var(string $original, array $renameMap): string {
    return $renameMap[$original] ?? $original;
}

/**
 * Format metrics into Prometheus exposition text format.
 *
 * @param array $metrics Collected metric data
 * @return string Formatted Prometheus metrics
 */
function generate_output(array $metrics): string {
    $output = "";
    log_error("output is Empty. Metrics size is " . count($metrics) ."");
    foreach ($metrics as $name => $entries) {
        // $output .= "# HELP $name UPS metric from NUT\n";
        // $output .= "# TYPE $name gauge\n";
        foreach ($entries as $entry) {
            $labels = implode(',', array_map(
                fn($k, $v) => "$k=\"$v\"",
                array_keys($entry['labels']),
                $entry['labels']
            ));
        
            $output .= "$name" . ($labels ? "{" . $labels . "}" : "") . " {$entry['value']}\n";
            

        }
    }
    return $output;
}

/**
 * Serve metrics from cache or regenerate them if expired.
 *
 * @param array $config Exporter configuration
 */
function serve_metrics(array $config): void {
    $ttl = $config['cache_ttl'] ?? 15;

    if (file_exists(CACHE_FILE) && (time() - filemtime(CACHE_FILE) < $ttl)) {
        echo file_get_contents(CACHE_FILE);
        return;
    }

    try {
        log_error("cGetting Metrics =======");

        $metrics = get_metrics($config);
        log_error("Generating outputs ======");
        $output = generate_output($metrics);
        log_error("Saving to cache =========");
        file_put_contents(CACHE_FILE, $output);
        log_error("Printing output =========");
        echo $output;
    } catch (Throwable $e) {
        log_error("Fatal error: " . $e->getMessage());
        http_response_code(500);
        exit("Internal exporter error.");
    }
}

/**
 * Route and serve requests via built-in PHP HTTP server.
 *
 * @param array $config Exporter configuration
 */
function handle_request(array $config): void {
    $path = $_SERVER['REQUEST_URI'] ?? '/';
    $expected = $config['metrics_path'] ?? '/metrics';

    if (parse_url($path, PHP_URL_PATH) !== $expected) {
        http_response_code(404);
        log_error("Fatal error: not correct path it should be: " . $config['metrics_path'] ?? '/metrics');

        exit("404 Not Found");
    }

    header("Content-Type: text/plain; version=0.0.4");
    serve_metrics($config);
}

// Entrypoint
$config = load_config();
handle_request($config);
