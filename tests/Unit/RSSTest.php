<?php

namespace Tests\Unit;

use Tests\TestCase;

class RSSTest extends TestCase {
    private string $sampleXml = '<?xml version="1.0" encoding="UTF-8"?>
<rss xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" version="2.0">
  <channel>
    <title>Test Podcast</title>
    <description>A test description</description>
    <link>https://example.com</link>
    <itunes:image href="https://example.com/image.jpg"/>
    <item>
      <title>Episode 1</title>
      <pubDate>Mon, 01 Jan 2024 12:00:00 +0000</pubDate>
      <guid>ep1</guid>
      <description>Ep 1 description</description>
      <itunes:duration>01:00:00</itunes:duration>
      <enclosure url="https://example.com/ep1.mp3" length="12345" type="audio/mpeg"/>
    </item>
    <item>
      <title>Episode 2</title>
      <pubDate>Tue, 02 Jan 2024 12:00:00 +0000</pubDate>
      <guid>ep2</guid>
      <itunes:duration>30:00</itunes:duration>
      <enclosure url="https://example.com/ep2.mp3" length="67890" type="audio/mpeg"/>
    </item>
  </channel>
</rss>';

    public function testParseFeed() {
        $feed = parse_feed($this->sampleXml);
        
        $this->assertNotNull($feed);
        $this->assertEquals('Test Podcast', $feed['title']);
        $this->assertEquals('https://example.com/image.jpg', $feed['image_url']);
        $this->assertCount(2, $feed['episodes']);
        
        $ep1 = $feed['episodes'][0];
        $this->assertEquals('Episode 1', $ep1['title']);
        $this->assertEquals('ep1', $ep1['guid']);
        $this->assertEquals(3600, $ep1['duration_seconds']);
        $this->assertEquals('https://example.com/ep1.mp3', $ep1['audio_url']);
        
        $ep2 = $feed['episodes'][1];
        $this->assertEquals('Episode 2', $ep2['title']);
        $this->assertEquals(1800, $ep2['duration_seconds']);
    }

    public function testParseInvalidFeed() {
        $this->assertEquals(null, parse_feed('invalid xml'));
        $this->assertEquals(null, parse_feed('<rss><not-a-channel></not-a-channel></rss>'));
    }

    public function testDurationParsing() {
        // We can test parse_episode indirectly or if we make it accessible.
        // It's a global function so we can call it if we have a SimpleXMLElement.
        
        $xml = new \SimpleXMLElement('<item xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">
            <title>Test</title>
            <itunes:duration>1:05:05</itunes:duration>
            <enclosure url="http://test.com/a.mp3" />
        </item>');
        
        $ep = parse_episode($xml);
        $this->assertEquals(3905, $ep['duration_seconds']);
        
        $xml2 = new \SimpleXMLElement('<item xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">
            <title>Test 2</title>
            <itunes:duration>45</itunes:duration>
            <enclosure url="http://test.com/b.mp3" />
        </item>');
        $ep2 = parse_episode($xml2);
        $this->assertEquals(45, $ep2['duration_seconds']);
    }
}
