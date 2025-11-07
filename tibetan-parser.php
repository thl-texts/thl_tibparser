<?php
/*
Plugin Name: Tibetan Phrase Parser API
Description: Parses Tibetan or Wylie phrases using a SOLR dictionary and exposes a REST API endpoint.
Version: 1.0
Author: Than Grove
*/

use function PHPSTORM_META\type;

if (!defined('ABSPATH')) exit;
mb_regex_encoding('UTF-8');
class Tibetan_Phrase_Parser {

    private $solr_url;

    private $dbug = array();

    public function __construct() {
        $this->solr_url = get_option('tibetan_solr_url', 'https://mandala-index.internal.lib.virginia.edu/solr/kmassets/select');

        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function register_routes() {
        // Path is eg: /wp-json/tibetan/v1/parse?q=chos%20sku%20ngo%20bo%20nyid
        register_rest_route('tibetan/v1', '/parse', [
            'methods' => ['GET', 'POST'],
            'callback' => [$this, 'handle_parse_request'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_parse_request($request) {
        $phrase = trim(sanitize_text_field($request->get_param('text')));
        $dicts = trim(sanitize_text_field($request->get_param('dicts')));
        $show_debug = in_array($request->get_param('debug'), ['1','true', 'on', 'yes', 'debug']);

        $this->dbug[] = $phrase;
        if (empty($phrase)) {
            return new WP_Error('empty_phrase', 'Phrase is empty', ['status' => 400]);
        }

        // Detect script type
        $is_tibetan = $this->is_tibetan_unicode($phrase);

        $script_type = $is_tibetan ? 'tibetan' : 'wylie';

        // Trim of tseks,puncutaion or numeral or spaces from the beginning
        if(preg_match('/[\x{0F00}-\x{0F3F}\s_\/]*(.*)$/u', $phrase, $matches)) {
            //$this->dbug[] = $matches;
            $phrase = $matches[1];
        }

        // Choose splitting based on type
        $subphrases = $this->split_phrase($phrase, $script_type);
        //$this->dbug[] = $subphrases;
        $parsed = [];
        foreach ($subphrases as $sub) {
            $this->dbug[] = "Doing: $sub";
            $parsed = array_merge($parsed, $this->parse_subphrase($sub, $script_type, $parsed));
        }

        $returnvar =  [
            'original_phrase' => $phrase,
            'script_type' => $script_type,
            'parsed' => $parsed,
        ];
        if ($show_debug) {
            $returnvar['debug'] = $this->dbug;
        }
        return $returnvar;
    }

    private function is_tibetan_unicode($string) {
        return (bool) preg_match('/[\x{0F00}-\x{0FFF}]/u', $string);
    }

    private function split_phrase($phrase, $type) {
        if ($type === 'tibetan') {
            // Split on Tibetan shad or spaces
            return preg_split('/[།༏༔༐༒༑།༈\s,]+/u', $phrase, -1, PREG_SPLIT_NO_EMPTY);
        } else {
            // For Wylie, split on spaces or punctuation
            return preg_split('/[;:\[\]|\/!_,]+/', $phrase, -1, PREG_SPLIT_NO_EMPTY);
        }
    }

    private function parse_subphrase($subphrase, $type, &$alreadyparsed) {
        $results = [];
        $c = 0;
        $subphrase = mb_ereg_replace('་+$', '', $subphrase); // remove trailing tsek
        $original_phrase = $subphrase;

        while (!empty($subphrase)) {
            $c++;
            if ($c > 100) { break; }
            $this->dbug[] = "subphrase: $subphrase";
            $this->dbug[] = $results;
            //$this->dbug[] ="Already parsed:";
            //$this->dbug[] = $alreadyparsed;
            if ($this->alreadyFound($subphrase, $results, $alreadyparsed)) {
                $subphrase = $this->calculateNewSubphrase($original_phrase, $subphrase);
                continue;
            }
            $match = $this->query_solr($subphrase, $type);
            if ($match) {
                $results[] = $match;
                $matched_word = ($type === 'tibetan') ? $match['tibetan'] : rtrim($match['wylie'], '/');
                if (is_array($matched_word)) {
                    $matched_word = (count($matched_word) > 0) ? $matched_word[0] : '';
                }
                $subphrase = $this->calculateNewSubphrase($original_phrase, $matched_word);
                $original_phrase = $subphrase;
            } else {
                //$this->dbug[] = "no match. Subphrase: $subphrase, Original phrase: $original_phrase";
                $last_delim = ($type === 'tibetan')
                    ? mb_strrpos($subphrase, '་')
                    : mb_strrpos($subphrase, ' ');

                if ($last_delim === false) {
                    $results[] = [
                        'id' => null,
                        'tibetan' => $subphrase,
                        'wylie' => $subphrase,
                        'matched' => false
                    ];
                    $subphrase = $this->calculateNewSubphrase($original_phrase, $subphrase);
                    $original_phrase = $subphrase;
                } else {
                    $subphrase = mb_substr($subphrase, 0, $last_delim);
                }
            }
        }
        return $results;
    }

    private function calculateNewSubphrase($original_phrase, $matched_word) {
        $subphrase = mb_substr($original_phrase, mb_strlen($matched_word));
        /* Check if subphrase starts with: (?: ... ) = non-captured grouping
            1. gigu
            2. naro
            3. a-chung with gigu
            4. any number of spaces, tseks, or shads
           If so, remove them but just taking everything else: (.*)$/u => $matches[1]
        */
        if (preg_match('/^(?:[\x{0F72}\x{0F7C}]|\x{0F60}\x{0F72})?[\x{0F0B}\x{0F0D}\s]+(.*)$/u', $subphrase, $matches)) {
            return $matches[1]; // if so remove them.
        }
        // otherwise phrase is fine
        return $subphrase;
    }

    private function alreadyFound($subphrase, &$results, &$alreadyparsed) {
        foreach ($alreadyparsed as $ite) {
            if ($subphrase === $ite['tibetan']) {
                return true;
            }
        }
        foreach ($results as $result) {
            if ($result['tibetan'] === $subphrase) {
                return true;
            }
        }
        return false;
    }

    private function query_solr($string, $type) {
        $query = $this->buildQuery($string, $type);
        $url = $this->solr_url . "?q=$query&fl=uid,id,header,name_tibt,name_latin&wt=json&rows=1";
        $this->dbug[] = "URL: $url";

        // Initialize cURL
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        // Browser-like headers
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36',
                'Accept: application/json, text/javascript, */*; q=0.01',
                'Accept-Language: en-US,en;q=0.9',
                'Accept-Encoding: gzip, deflate, br',
                'Connection: keep-alive',
                'Referer: https://staging.thlib.org',
                'DNT: 1',
                'Sec-Fetch-Site: same-origin',
                'Sec-Fetch-Mode: cors',
                'Sec-Fetch-Dest: empty',
        ]);

        // Optional: Enable SSL verification if needed
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        // Execute request
        $body = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $this->dbug[] = "cURL error: " . curl_error($ch);
            curl_close($ch);
            return false;
        }

        curl_close($ch);

        $this->dbug[] = "HTTP status code: $http_code";

        if ($http_code === 403) {
            $this->dbug[] = "403 Forbidden — server blocked the request";
            $this->dbug[] = "Response body (first 1000 chars): " . substr($body, 0, 1000);
            return false;
        } elseif ($http_code >= 400) {
            $this->dbug[] = "HTTP error $http_code";
            return false;
        }

        $this->dbug[] = "Response body length: " . strlen($body);

        return $body;
    }

    private function query_solr2($string, $type) {
        $query = $this->buildQuery($string, $type);
        $url = $this->solr_url . "?q=$query&fl=uid,id,header,name_tibt,name_latin&wt=json&rows=1";
        $this->dbug[] = "URL: $url";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36',
                'Accept: application/json',
                'Referer: https://staging.thlib.org',
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $this->dbug[] = "CURL Response: $response";
//
//        $response = wp_remote_get($url, [
//            'timeout' => 10,
//            'headers' => [
//                    'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36',
//                    'Accept'          => 'application/json, text/javascript, */*; q=0.01',
//                    'Accept-Language' => 'en-US,en;q=0.9',
//                    'Accept-Encoding' => 'gzip, deflate, br',
//                    'Connection'      => 'keep-alive',
//                    'Referer'         => 'https://staging.thlib.org',
//                    'DNT'             => '1',
//                    'Sec-Fetch-Site'  => 'same-origin',
//                    'Sec-Fetch-Mode'  => 'cors',
//                    'Sec-Fetch-Dest'  => 'empty',
//            ]
//        ]);
//        if (is_wp_error($response)) {
//            $error_message = $response->get_error_message();
//            $this->dbug[] = "HTTP ERROR: " . $error_message;
//            return false;
//        }
//
//        $body = wp_remote_retrieve_body($response);
//        $this->dbug[] = "Response: " . $body;
//        $data = json_decode($body, true);


        $data = json_decode($response, true);

        if (!empty($data['response']['docs'])) {
            $doc = $data['response']['docs'][0];
            return [
                'id' => $doc['id'] ?? null,
                'tibetan' => $doc['name_tibt'][0] ?? null,
                'wylie' => $doc['name_latin'][0] ?? $string,
                'matched' => true
            ];
        }
        return false;
    }

    private function buildQuery($string, $type) {
        $field = $type === 'tibetan' ? 'name_tibt' : 'name_latin';
        $query = '';
        if ($type === 'tibetan') {
            if (mb_ends_with($string, ['པར', 'བར'])) {
                $abbrstr = mb_substr($string, 0, -1);
                $query = "$field:(\"$string\" OR \"$abbrstr\")";
            } else if (mb_ends_with($string, ['འི', 'འོ'])) {
                $no_gen =  mb_substr($string, 0, -1);
                $no_a =  mb_substr($string, 0, -2);
                $query = "$field:(\"$no_gen\" OR \"$no_a\")";
            } else {
                $query = "$field:\"$string\"";
            }
        } else if ($type === 'wylie') {
            if (mb_ends_with($string, ['par', 'bar']))  {
                $abbrstr = mb_substr($string, 0, -1);
                $query = "$field:(\"$string/\" OR \"$abbrstr/\")";
            } else if (mb_ends_with($string, ['\'i', '\'o'])) {
                $no_gen =  mb_substr($string, 0, -1);
                $no_a =  mb_substr($string, 0, -2);
                $query = "$field:(\"$no_gen/\" OR \"$no_a/\")";
            } else {
                $query = "$field:\"$string/\"";
            }
        }
        // error_log("Debug type: " . gettype($this->dbug));
       //$this->dbug[] = "Query: $query";
        return urlencode($query);
    }

    // Admin page setup
    public function add_admin_page() {
        add_options_page('Tibetan Parser', 'Tibetan Parser', 'manage_options', 'tibetan-parser', [$this, 'render_admin_page']);
    }

    public function register_settings() {
        register_setting('tibetan_parser_settings', 'tibetan_solr_url', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
        ]);

        add_settings_section('main_section', 'Main Settings', '__return_false', 'tibetan-parser');
        add_settings_field('tibetan_solr_url', 'SOLR URL', function() {
            $value = esc_attr(get_option('tibetan_solr_url', 'https://mandala-index.internal.lib.virginia.edu/solr/kmassets/select'));
            echo '<input type="url" name="tibetan_solr_url" value="' . $value . '" class="regular-text" />';
        }, 'tibetan-parser', 'main_section');
    }

    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>Tibetan Parser Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('tibetan_parser_settings');
                do_settings_sections('tibetan-parser');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}

function mb_ends_with($haystack, $needle_var) {
    $needles = is_array($needle_var) ? $needle_var : array($needle_var);
    foreach ($needles as $needle) {
        if (mb_substr($haystack, -mb_strlen($needle)) === $needle) {
            return true;
        }
    }
    return false;
}

new Tibetan_Phrase_Parser();
