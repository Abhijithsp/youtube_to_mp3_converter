<?php

namespace Tests\Unit;

use App\Support\FileName;
use App\Support\TrackTitle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TrackTitleTest extends TestCase
{
    public static function titles(): array
    {
        return [
            ['D-Sturb - LADY (Official Music Video)', 'D-Sturb - LADY'],
            ['Artist - Song (Official Video) [HD]', 'Artist - Song'],
            ['Artist - Song [Official Music Video] (4K)', 'Artist - Song'],
            ['Artist - Song (Official HD Video)', 'Artist - Song'],
            ['Luvstruck - Girl (D-Sturb Remix) [Official Audio]', 'Luvstruck - Girl (D-Sturb Remix)'],
            ['Song Name | Official Lyric Video', 'Song Name'],
            ['Some Track (feat. X) (Lyrics)', 'Some Track (feat. X)'],
            ['Song 【MV】', 'Song'],
            ['Song (Visualizer)', 'Song'],
            ['Artist - Song #shorts #music', 'Artist - Song'],
            // Meaningful parts stay
            ['D-Sturb - LADY (Extended Mix)', 'D-Sturb - LADY (Extended Mix)'],
            ['Live at Wembley (Live)', 'Live at Wembley (Live)'],
            ['Audio Engineering Explained', 'Audio Engineering Explained'],
            // Never cleaned away completely
            ['Official Video', 'Official Video'],
        ];
    }

    #[DataProvider('titles')]
    public function test_noise_is_removed_from_titles(string $title, string $expected): void
    {
        $this->assertSame($expected, TrackTitle::clean($title));
    }

    public function test_artist_and_song_are_split(): void
    {
        $this->assertSame(['D-Sturb & Sefa', 'Nothing Like The Oldschool'], TrackTitle::split('D-Sturb & Sefa - Nothing Like The Oldschool'));
        $this->assertNull(TrackTitle::split('No separator here'));
        $this->assertNull(TrackTitle::split('Hyphenated-word title'));
    }

    public function test_tag_title_drops_the_artist_prefix(): void
    {
        $this->assertSame('LADY', TrackTitle::songTitle('D-Sturb - LADY', 'd-sturb'));
        $this->assertSame('D-Sturb - LADY', TrackTitle::songTitle('D-Sturb - LADY', 'Q-dance Music'));
        $this->assertSame('Song', TrackTitle::songTitle('Song', null));
    }

    public function test_suggestions_prefer_youtube_music_metadata(): void
    {
        $this->assertSame(
            ['title' => 'Daft Punk - One More Time', 'artist' => 'Daft Punk'],
            TrackTitle::suggest(['title' => 'One More Time', 'artist' => 'Daft Punk', 'track' => 'One More Time', 'has_music_metadata' => true]),
        );

        $this->assertSame(
            ['title' => 'D-Sturb - LADY', 'artist' => 'D-Sturb'],
            TrackTitle::suggest(['title' => 'D-Sturb - LADY (Official Music Video)', 'uploader' => 'Q-dance Music']),
        );

        $this->assertSame(
            ['title' => 'My Song (Official Video)', 'artist' => 'Channel'],
            TrackTitle::suggest(['title' => 'My Song (Official Video)', 'uploader' => 'Channel'], clean: false),
        );
    }

    public function test_file_names_keep_the_title_but_drop_invalid_characters(): void
    {
        $this->assertSame('D-Sturb - LADY (Official Music Video)', FileName::fromTitle('D-Sturb - LADY (Official Music Video)'));
        $this->assertSame('AC DC Back In Black Live', FileName::fromTitle('AC/DC: Back\\In "Black" | Live?'));
        $this->assertSame('ആവേശം - ഇലുമിനാറ്റി', FileName::fromTitle('ആവേശം - ഇലുമിനാറ്റി'));
        $this->assertSame('Song', FileName::fromTitle('Song. '));
        $this->assertSame('fallback', FileName::fromTitle('CON', 'fallback'));
        $this->assertSame('fallback', FileName::fromTitle('...', 'fallback'));
        $this->assertLessThanOrEqual(180, strlen(FileName::fromTitle(str_repeat('ആ', 200))));
    }
}
