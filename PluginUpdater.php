<?php

class PluginUpdater {

    private $slug;
    private $pluginData;
    private $username;
    private $repo;
    private $pluginFile;
    private $githubAPIResult;
    private $accessToken;

    function __construct( $pluginFile, $gitHubUsername, $gitHubProjectName, $accessToken = '' ) {
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'setTransitent' ) );
        add_filter( 'plugins_api', array( $this, 'setPluginInfo' ), 10, 3 );
        add_filter( 'upgrader_post_install', array( $this, 'postInstall' ), 10, 3 );

        $this->pluginFile  = $pluginFile;
        $this->username    = $gitHubUsername;
        $this->repo        = $gitHubProjectName;
        $this->accessToken = $accessToken;
    }

    private function initPluginData() {
        $this->slug       = plugin_basename( $this->pluginFile );
        $this->pluginData = get_plugin_data( $this->pluginFile );
    }

    private function getRepoReleaseInfo() {
        if ( ! empty( $this->githubAPIResult ) ) {
            return;
        }

        $url = "https://api.github.com/repos/{$this->username}/{$this->repo}/releases";

        if ( ! empty( $this->accessToken ) ) $url = add_query_arg( array( 'access_token' => $this->accessToken ), $url );

        $this->githubAPIResult = wp_remote_retrieve_body( wp_remote_get( $url ) );

        if ( ! empty( $this->githubAPIResult ) ) $this->githubAPIResult = @json_decode( $this->githubAPIResult );

        if ( is_array( $this->githubAPIResult ) ) $this->githubAPIResult = $this->githubAPIResult[0];
    }

    public function setTransitent( $transient ) {
        if ( empty( $transient->checked ) ) return $transient;

        $this->initPluginData();
        $this->getRepoReleaseInfo();

        $doUpdate = 0;

        if(isset($this->githubAPIResult->tag_name) && isset($transient->checked[$this->slug])) {
            $doUpdate = version_compare($this->githubAPIResult->tag_name, $transient->checked[$this->slug]);
        }

        if ( $doUpdate == 1 ) {
            $package = $this->githubAPIResult->zipball_url;

            if ( !empty( $this->accessToken ) ) $package = add_query_arg( array( 'access_token' => $this->accessToken ), $package );

            $obj = new stdClass();
            $obj->slug                        = $this->slug;
            $obj->new_version                 = $this->githubAPIResult->tag_name;
            $obj->url                         = $this->pluginData['PluginURI'];
            $obj->package                     = $package;
            $transient->response[$this->slug] = $obj;
        }

        return $transient;
    }

    public function setPluginInfo( $false, $action, $response ) {
        $this->initPluginData();
        $this->getRepoReleaseInfo();

        if ( empty( $response->slug ) || $response->slug != $this->slug ) return false;

        $response->last_updated  = $this->githubAPIResult->published_at;
        $response->slug          = $this->slug;
        $response->name          = $this->pluginData['Name'];
        $response->version       = $this->githubAPIResult->tag_name;
        $response->author        = $this->pluginData['AuthorName'];
        $response->homepage      = $this->pluginData['PluginURI'];
        $response->download_link = $this->githubAPIResult->zipball_url;

        if ( !empty( $this->accessToken ) ) $response->download_link = add_query_arg( array( 'access_token' => $this->accessToken ), $response->download_link );

        $response->sections = array(
            'description' => $this->pluginData["Description"],
            'changelog' => class_exists( "Parsedown" ) ? Parsedown::instance()->parse( $this->githubAPIResult->body ) : $this->githubAPIResult->body
        );

        preg_match( "/requires:\s([\d\.]+)/i", $this->githubAPIResult->body, $matches );

        if ( ! empty( $matches ) && is_array( $matches ) && count( $matches ) > 1) $response->requires = $matches[1];

        preg_match( "/tested:\s([\d\.]+)/i", $this->githubAPIResult->body, $matches );
        if ( ! empty( $matches ) && is_array( $matches ) && count( $matches ) > 1) $response->tested = $matches[1];

        return $response;
    }

    public function postInstall( $true, $hook_extra, $result ) {
        global $wp_filesystem;

        $this->initPluginData();

        $wasActivated = is_plugin_active( $this->slug );
        $pluginFolder = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname( $this->slug );

        $wp_filesystem->move( $result['destination'], $pluginFolder );

        $result['destination'] = $pluginFolder;

        if ( $wasActivated ) activate_plugin( $this->slug );

        return $result;
    }
}
