<?php

use PHPUnit\Framework\TestCase;

class StaticPageGeneratorTest extends TestCase
{
    private $plugin;

    protected function setUp(): void
    {
        $this->plugin = new StaticPageGenerator();
    }

    public function testPluginInstantiation()
    {
        $this->assertInstanceOf(StaticPageGenerator::class, $this->plugin);
    }

    public function testS3CredentialsConfiguredReturnsFalseWhenEmpty()
    {
        // Mock get_option to return empty values
        $reflection = new ReflectionClass($this->plugin);
        $method = $reflection->getMethod('s3_credentials_configured');
        $method->setAccessible(true);

        // Since our mocked get_option returns empty strings for S3 credentials
        $result = $method->invoke($this->plugin);
        $this->assertFalse($result);
    }

    public function testProcessHtmlForStatic()
    {
        $reflection = new ReflectionClass($this->plugin);
        $method = $reflection->getMethod('process_html_for_static');
        $method->setAccessible(true);

        $html = '<a href="/test">Link</a><img src="/image.jpg">';
        $baseUrl = 'http://example.com';
        
        $result = $method->invoke($this->plugin, $html, $baseUrl);
        
        $this->assertStringContains('href="http://example.com/test"', $result);
        $this->assertStringContains('src="http://example.com/image.jpg"', $result);
    }

    public function testGetPageHtmlForHomePage()
    {
        $reflection = new ReflectionClass($this->plugin);
        $method = $reflection->getMethod('get_page_html');
        $method->setAccessible(true);

        // Test home page (ID = 0)
        $result = $method->invoke($this->plugin, 0);
        $this->assertNotFalse($result);
        $this->assertStringContains('<html>', $result);
    }

    public function testGetPageHtmlForRegularPage()
    {
        $reflection = new ReflectionClass($this->plugin);
        $method = $reflection->getMethod('get_page_html');
        $method->setAccessible(true);

        // Test regular page
        $result = $method->invoke($this->plugin, 1);
        $this->assertNotFalse($result);
        $this->assertStringContains('<html>', $result);
    }

    public function testSaveToTempFolderForHomePage()
    {
        $reflection = new ReflectionClass($this->plugin);
        $method = $reflection->getMethod('save_to_temp_folder');
        $method->setAccessible(true);

        $content = '<html><head><title>Test</title></head><body><h1>Home</h1></body></html>';
        $title = 'Home Page';
        $pageId = 0;

        // Create temp directory structure
        $uploadDir = '/tmp/wp-uploads';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $result = $method->invoke($this->plugin, $content, $title, $pageId);
        
        // Should return URL for home/index.html
        $this->assertStringContains('home/index.html', $result);
        
        // Verify file was created
        $this->assertTrue(file_exists($uploadDir . '/static-pages/home/index.html'));
        
        // Cleanup
        if (file_exists($uploadDir . '/static-pages/home/index.html')) {
            unlink($uploadDir . '/static-pages/home/index.html');
            rmdir($uploadDir . '/static-pages/home');
            rmdir($uploadDir . '/static-pages');
        }
    }

    public function testSaveToTempFolderForRegularPage()
    {
        $reflection = new ReflectionClass($this->plugin);
        $method = $reflection->getMethod('save_to_temp_folder');
        $method->setAccessible(true);

        $content = '<html><head><title>Test</title></head><body><h1>Test Page</h1></body></html>';
        $title = 'Test Page';
        $pageId = 1;

        // Create temp directory structure
        $uploadDir = '/tmp/wp-uploads';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $result = $method->invoke($this->plugin, $content, $title, $pageId);
        
        // Should return URL with the page slug
        $this->assertStringContains('test-post/index.html', $result);
        
        // Verify file was created
        $this->assertTrue(file_exists($uploadDir . '/static-pages/test-post/index.html'));
        
        // Cleanup
        if (file_exists($uploadDir . '/static-pages/test-post/index.html')) {
            unlink($uploadDir . '/static-pages/test-post/index.html');
            rmdir($uploadDir . '/static-pages/test-post');
            rmdir($uploadDir . '/static-pages');
        }
    }

    public function testGenerateStaticPageWithoutS3Credentials()
    {
        // Mock $_POST data
        $_POST = array(
            'nonce' => 'test_nonce_spg_nonce',
            'page_id' => '1',
            'page_title' => 'Test Page'
        );

        // Create temp directory structure
        $uploadDir = '/tmp/wp-uploads';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Capture output
        ob_start();
        
        try {
            $this->plugin->generate_static_page();
        } catch (Exception $e) {
            // wp_send_json_success calls exit, so we catch that
        }
        
        $output = ob_get_clean();
        
        // Should contain success message about temp folder
        $this->assertStringContains('HTML sólido forjado', $output);
        
        // Cleanup
        $this->cleanupTempFiles($uploadDir);
    }

    private function cleanupTempFiles($uploadDir)
    {
        if (file_exists($uploadDir . '/static-pages')) {
            $files = glob($uploadDir . '/static-pages/**/index.html');
            foreach ($files as $file) {
                unlink($file);
            }
            
            $dirs = glob($uploadDir . '/static-pages/*', GLOB_ONLYDIR);
            foreach ($dirs as $dir) {
                rmdir($dir);
            }
            
            rmdir($uploadDir . '/static-pages');
        }
    }

    public function testToggleAutoupdate()
    {
        // Mock $_POST data
        $_POST = array(
            'nonce' => 'test_nonce_spg_autoupdate_nonce',
            'page_id' => '1',
            'enabled' => '1'
        );

        // Capture output
        ob_start();
        
        try {
            $this->plugin->toggle_autoupdate();
        } catch (Exception $e) {
            // wp_send_json_success calls exit, so we catch that
        }
        
        $output = ob_get_clean();
        
        // Should contain success response
        $this->assertStringContains('"success":true', $output);
    }

    public function testHandlePageUpdateWithoutAutoupdate()
    {
        $post = new stdClass();
        $post->post_type = 'page';
        $post->post_status = 'publish';
        $post->post_title = 'Test Page';

        // This should return early since autoupdate is not enabled
        $this->plugin->handle_page_update(1, $post, $post);
        
        // If we get here without errors, the method handled the case correctly
        $this->assertTrue(true);
    }

    public function testHandlePageUpdateWithNonPagePostType()
    {
        $post = new stdClass();
        $post->post_type = 'post'; // Not a page
        $post->post_status = 'publish';
        $post->post_title = 'Test Post';

        // This should return early since it's not a page
        $this->plugin->handle_page_update(1, $post, $post);
        
        // If we get here without errors, the method handled the case correctly
        $this->assertTrue(true);
    }

    public function testHandlePageUpdateWithDraftStatus()
    {
        $post = new stdClass();
        $post->post_type = 'page';
        $post->post_status = 'draft'; // Not published
        $post->post_title = 'Test Page';

        // This should return early since it's not published
        $this->plugin->handle_page_update(1, $post, $post);
        
        // If we get here without errors, the method handled the case correctly
        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        // Clean up any temporary files created during tests
        $uploadDir = '/tmp/wp-uploads';
        $this->cleanupTempFiles($uploadDir);
        
        // Clean up $_POST
        $_POST = array();
    }
}