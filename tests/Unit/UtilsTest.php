<?php

namespace Tests\Unit;

use Tests\TestCase;

class UtilsTest extends TestCase {
    public function testSlugify() {
        $this->assertEquals('hello-world', slugify('Hello World!'));
        $this->assertEquals('test-case-123', slugify('Test Case #123'));
        $this->assertEquals('my-long-podcast-title-is-shortened-to-40', slugify('My Long Podcast Title Is Shortened To 40 Characters'));
        $this->assertEquals('podcast', slugify('!!!'));
    }

    public function testUniqueSlug() {
        $feeds = ['test' => []];
        $this->assertEquals('test-2', unique_slug('test', $feeds));
        
        $feeds['test-2'] = [];
        $this->assertEquals('test-3', unique_slug('test', $feeds));
        
        $this->assertEquals('new-slug', unique_slug('new-slug', $feeds));
    }

    public function testSafeFilename() {
        $this->assertEquals('my-episode.mp3', safe_filename('My Episode', 'https://example.com/audio.mp3'));
        $this->assertEquals('another-one.m4a', safe_filename('Another One!', 'https://example.com/file.m4a?query=1'));
        $this->assertEquals('no-extension.mp3', safe_filename('No Extension', 'https://example.com/path'));
    }

    public function testFindEpisodeIdx() {
        $episodes = [
            ['guid' => 'abc', 'title' => 'Ep 1'],
            ['guid' => 'def', 'title' => 'Ep 2'],
            ['guid' => 'ghi', 'title' => 'Ep 3'],
        ];

        $this->assertEquals(1, find_episode_idx($episodes, 2, 'def'));
        $this->assertEquals(0, find_episode_idx($episodes, 1, 'abc'));
        $this->assertEquals(2, find_episode_idx($episodes, 5, 'ghi')); // Matches by GUID even if ep num is wrong
        $this->assertEquals(1, find_episode_idx($episodes, 2, null)); // Matches by index if no GUID
        $this->assertEquals(-1, find_episode_idx($episodes, 10, null)); // No match
    }

    public function testLoadSaveFeeds() {
        $feeds = ['test' => ['title' => 'Test Podcast']];
        save_feeds($feeds); // Create initial file
        
        $feeds['test']['title'] = 'Updated title';
        save_feeds($feeds); // Should create .bak
        
        $loaded = load_feeds();
        $this->assertEquals($feeds, $loaded);
        $this->assertTrue(file_exists(FEEDS_FILE));
        $this->assertTrue(file_exists(FEEDS_FILE . '.bak'));
    }
}
