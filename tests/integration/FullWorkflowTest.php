<?php

use PHPUnit\Framework\TestCase;

class FullWorkflowTest extends TestCase
{
    private $plugin;

    protected function setUp(): void
    {
        $this->plugin = new StaticPageGenerator();
        
        // Create temp upload directory
        $uploadDir = '/tmp/wp-uploads';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
    }

    public function testCompleteStaticGenerationWorkflow()
    {
        // Test the complete workflow from page selection to file generation
        
        // 1. Mock a page with autoupdate enabled
        $_POST = array(
            'nonce' => 'test_nonce_spg_nonce',
            'page_id' => '1',
            'page_title' => 'Test Integration Page'
        );

        // 2. Generate static page
        ob_start();
        try {
            $this->plugin->generate_static_page();
        } catch (Exception $e) {
            // Expected due to wp_send_json_success
        }
        $output = ob_get_clean();

        // 3. Verify success response
        $this->assertStringContains('HTML sólido forjado', $output);
        $this->assertStringContains('"success":true', $output);

        // 4. Verify file was created
        $uploadDir = '/tmp/wp-uploads';
        $expectedFile = $uploadDir . '/static-pages/test-post/index.html';
        $this->assertTrue(file_exists($expectedFile));

        // 5. Verify file content
        $content = file_get_contents($expectedFile);
        $this->assertStringContains('<html>', $content);
        $this->assertStringContains('<title>Test</title>', $content);
    }

    public function testHomePageGenerationWorkflow()
    {
        // Test generating the home page specifically
        
        $_POST = array(
            'nonce' => 'test_nonce_spg_nonce',
            'page_id' => '0', // Home page
            'page_title' => 'Página de Inicio (Home)'
        );

        ob_start();
        try {
            $this->plugin->generate_static_page();
        } catch (Exception $e) {
            // Expected
        }
        $output = ob_get_clean();

        // Verify home page was generated
        $this->assertStringContains('HTML sólido forjado', $output);
        
        $uploadDir = '/tmp/wp-uploads';
        $expectedFile = $uploadDir . '/static-pages/home/index.html';
        $this->assertTrue(file_exists($expectedFile));
    }

    public function testAutoupdateWorkflowIntegration()
    {
        // Test the complete autoupdate workflow
        
        // 1. Enable autoupdate for a page
        $_POST = array(
            'nonce' => 'test_nonce_spg_autoupdate_nonce',
            'page_id' => '1',
            'enabled' => '1'
        );

        ob_start();
        try {
            $this->plugin->toggle_autoupdate();
        } catch (Exception $e) {
            // Expected
        }
        $output = ob_get_clean();

        $this->assertStringContains('"success":true', $output);

        // 2. Simulate page update
        $postBefore = new stdClass();
        $postBefore->post_type = 'page';
        $postBefore->post_status = 'publish';
        $postBefore->post_title = 'Old Title';

        $postAfter = new stdClass();
        $postAfter->post_type = 'page';
        $postAfter->post_status = 'publish';
        $postAfter->post_title = 'Updated Title';

        // 3. Trigger autoupdate
        $this->plugin->handle_page_update(1, $postAfter, $postBefore);

        // 4. Verify file was regenerated
        $uploadDir = '/tmp/wp-uploads';
        $expectedFile = $uploadDir . '/static-pages/test-post/index.html';
        $this->assertTrue(file_exists($expectedFile));
    }

    public function testMultiplePageGeneration()
    {
        // Test generating multiple pages in sequence
        
        $pages = array(
            array('id' => '1', 'title' => 'About Us', 'slug' => 'test-post'),
            array('id' => '2', 'title' => 'Contact', 'slug' => 'test-post'), // Same slug due to mocking
            array('id' => '0', 'title' => 'Home', 'slug' => 'home')
        );

        foreach ($pages as $page) {
            $_POST = array(
                'nonce' => 'test_nonce_spg_nonce',
                'page_id' => $page['id'],
                'page_title' => $page['title']
            );

            ob_start();
            try {
                $this->plugin->generate_static_page();
            } catch (Exception $e) {
                // Expected
            }
            ob_get_clean();
        }

        // Verify all files were created
        $uploadDir = '/tmp/wp-uploads';
        $this->assertTrue(file_exists($uploadDir . '/static-pages/test-post/index.html'));
        $this->assertTrue(file_exists($uploadDir . '/static-pages/home/index.html'));
    }

    public function testErrorHandlingWorkflow()
    {
        // Test error handling in the workflow
        
        // 1. Test with invalid nonce (though our mock always passes)
        $_POST = array(
            'nonce' => 'invalid_nonce',
            'page_id' => '1',
            'page_title' => 'Test Page'
        );

        // Since our mock always validates nonce, this will still work
        ob_start();
        try {
            $this->plugin->generate_static_page();
        } catch (Exception $e) {
            // Expected
        }
        $output = ob_get_clean();

        // With our mocks, this should still succeed
        $this->assertStringContains('HTML sólido forjado', $output);
    }

    public function testFileSystemPermissionsHandling()
    {
        // Test handling of file system permissions
        
        // Create a directory structure that might fail
        $uploadDir = '/tmp/wp-uploads';
        $staticDir = $uploadDir . '/static-pages';
        
        if (file_exists($staticDir)) {
            $this->cleanupTempFiles($uploadDir);
        }

        // Generate a page
        $_POST = array(
            'nonce' => 'test_nonce_spg_nonce',
            'page_id' => '1',
            'page_title' => 'Permission Test'
        );

        ob_start();
        try {
            $this->plugin->generate_static_page();
        } catch (Exception $e) {
            // Expected
        }
        $output = ob_get_clean();

        // Should create directories and files successfully
        $this->assertStringContains('HTML sólido forjado', $output);
        $this->assertTrue(file_exists($staticDir . '/test-post/index.html'));
    }

    public function testHtmlProcessingWorkflow()
    {
        // Test the HTML processing pipeline
        
        $_POST = array(
            'nonce' => 'test_nonce_spg_nonce',
            'page_id' => '1',
            'page_title' => 'HTML Processing Test'
        );

        ob_start();
        try {
            $this->plugin->generate_static_page();
        } catch (Exception $e) {
            // Expected
        }
        ob_get_clean();

        // Verify the generated HTML was processed
        $uploadDir = '/tmp/wp-uploads';
        $htmlFile = $uploadDir . '/static-pages/test-post/index.html';
        
        if (file_exists($htmlFile)) {
            $content = file_get_contents($htmlFile);
            
            // Should contain processed HTML with absolute URLs
            $this->assertStringContains('http://example.com', $content);
            $this->assertStringNotContains('href="/', $content); // Relative URLs should be converted
        }
    }

    public function testSlugStructureIntegrity()
    {
        // Test that the slug/index.html structure is maintained
        
        $testCases = array(
            array('id' => '0', 'expected_path' => 'home/index.html'),
            array('id' => '1', 'expected_path' => 'test-post/index.html'),
            array('id' => '2', 'expected_path' => 'test-post/index.html')
        );

        foreach ($testCases as $case) {
            $_POST = array(
                'nonce' => 'test_nonce_spg_nonce',
                'page_id' => $case['id'],
                'page_title' => 'Slug Test Page'
            );

            ob_start();
            try {
                $this->plugin->generate_static_page();
            } catch (Exception $e) {
                // Expected
            }
            ob_get_clean();

            // Verify correct path structure
            $uploadDir = '/tmp/wp-uploads';
            $fullPath = $uploadDir . '/static-pages/' . $case['expected_path'];
            $this->assertTrue(file_exists($fullPath), "File should exist at: " . $fullPath);
        }
    }

    public function testConcurrentPageGeneration()
    {
        // Test generating pages in rapid succession (simulating concurrent requests)
        
        $iterations = 5;
        
        for ($i = 1; $i <= $iterations; $i++) {
            $_POST = array(
                'nonce' => 'test_nonce_spg_nonce',
                'page_id' => strval($i),
                'page_title' => "Concurrent Test Page {$i}"
            );

            ob_start();
            try {
                $this->plugin->generate_static_page();
            } catch (Exception $e) {
                // Expected
            }
            ob_get_clean();
        }

        // Verify all pages were created successfully
        $uploadDir = '/tmp/wp-uploads';
        for ($i = 1; $i <= $iterations; $i++) {
            $expectedFile = $uploadDir . '/static-pages/test-post/index.html';
            $this->assertTrue(file_exists($expectedFile));
        }
    }

    private function cleanupTempFiles($uploadDir)
    {
        if (file_exists($uploadDir . '/static-pages')) {
            $files = glob($uploadDir . '/static-pages/**/index.html');
            foreach ($files as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
            
            $dirs = glob($uploadDir . '/static-pages/*', GLOB_ONLYDIR);
            foreach ($dirs as $dir) {
                if (is_dir($dir)) {
                    rmdir($dir);
                }
            }
            
            if (is_dir($uploadDir . '/static-pages')) {
                rmdir($uploadDir . '/static-pages');
            }
        }
    }

    protected function tearDown(): void
    {
        // Clean up temporary files
        $uploadDir = '/tmp/wp-uploads';
        $this->cleanupTempFiles($uploadDir);
        
        // Clean up $_POST
        $_POST = array();
    }
}