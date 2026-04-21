<?php

namespace Tests\Unit;

use Tests\TestCase;

class ActionsTest extends TestCase {
    private string $sampleXml = '<?xml version="1.0" encoding="UTF-8"?>
<rss xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" version="2.0">
  <channel>
    <title>Test Podcast</title>
    <item>
      <title>Episode 1</title>
      <guid>ep1</guid>
      <enclosure url="https://example.com/ep1.mp3" length="12345" type="audio/mpeg"/>
    </item>
  </channel>
</rss>';

    public function setUp(): void {
        global $fetch_mock;
        $fetch_mock = null;
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        if (file_exists(FEEDS_FILE)) unlink(FEEDS_FILE);
    }

    public function testActionAdd() {
        global $fetch_mock;
        $fetch_mock = function($url) {
            return ['ok' => true, 'body' => $this->sampleXml];
        };

        $_POST['url'] = 'https://example.com/rss.xml';
        
        $res = action_add();
        $this->assertArrayHasKey('success', $res);
        $this->assertTrue(str_contains($res['success'], 'Test Podcast'));
        
        $feeds = load_feeds();
        $this->assertArrayHasKey('test-podcast', $feeds);
        $this->assertEquals('https://example.com/rss.xml', $feeds['test-podcast']['url']);
        $this->assertCount(1, $feeds['test-podcast']['episodes']);
    }

    public function testActionRemove() {
        // Setup a feed first
        $feeds = ['test' => ['meta' => ['title' => 'Test'], 'episodes' => []]];
        save_feeds($feeds);
        
        $_POST['slug'] = 'test';
        $res = action_remove();
        $this->assertArrayHasKey('success', $res);
        
        $feeds = load_feeds();
        $this->assertFalse(isset($feeds['test']));
    }

    public function testActionMarkPlayed() {
        $feeds = ['test' => [
            'meta' => ['title' => 'Test'],
            'episodes' => [
                ['guid' => 'ep1', 'title' => 'Ep 1', 'played' => false],
                ['guid' => 'ep2', 'title' => 'Ep 2', 'played' => false],
            ]
        ]];
        save_feeds($feeds);
        
        // Mark one episode as played
        $_POST['slug'] = 'test';
        $_POST['guid'] = 'ep1';
        $res = action_mark_played();
        $this->assertArrayHasKey('success', $res);
        
        $feeds = load_feeds();
        $this->assertTrue($feeds['test']['episodes'][0]['played']);
        $this->assertFalse($feeds['test']['episodes'][1]['played']);
        
        // Mark all as played
        $_POST['guid'] = null;
        action_mark_played();
        $feeds = load_feeds();
        $this->assertTrue($feeds['test']['episodes'][1]['played']);
    }

    public function testActionSaveProgress() {
        $feeds = ['test' => [
            'meta' => ['title' => 'Test'],
            'episodes' => [
                ['guid' => 'ep1', 'title' => 'Ep 1', 'played' => false],
            ]
        ]];
        save_feeds($feeds);
        
        $_POST['slug'] = 'test';
        $_POST['guid'] = 'ep1';
        $_POST['position'] = 100.5;
        $_POST['duration'] = 1000.0;
        
        $res = action_save_progress();
        $this->assertTrue($res['success']);
        
        $feeds = load_feeds();
        $this->assertEquals(100.5, $feeds['test']['episodes'][0]['progress']);
        $this->assertEquals(1000, $feeds['test']['episodes'][0]['duration_seconds']);
        $this->assertFalse($feeds['test']['episodes'][0]['played']);
        
        // Test auto-mark as played (> 95%)
        $_POST['position'] = 960.0;
        $res = action_save_progress();
        $this->assertTrue($res['auto_played']);
        
        $feeds = load_feeds();
        $this->assertTrue($feeds['test']['episodes'][0]['played']);
    }
}
