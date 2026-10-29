<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Admin;

use FreifunkKarte\Admin\Html;
use FreifunkKarte\Admin\Safe;
use FreifunkKarte\Admin\Svg;
use PHPUnit\Framework\TestCase;

final class HtmlTest extends TestCase
{
    public function testTextIsEscaped(): void
    {
        $this->assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &#039;', Html::e('<script>alert("x")</script> & \''));
        $this->assertSame('42', Html::e(42));
        $this->assertSame('', Html::e(null));
    }

    public function testCellsEscapePlainValuesAndPassSafeHtmlThrough(): void
    {
        $this->assertSame('&lt;b&gt;', Html::cell('<b>'));
        $this->assertSame('<b>x</b>', Html::cell(new Safe('<b>x</b>')));
        $this->assertSame('<span class="muted">-</span>', Html::cell(null));
        $this->assertSame('<span class="muted">-</span>', Html::cell(''));
        $this->assertSame('0', Html::cell(0), 'zero is a value');
    }

    public function testLinkEscapesPathLabelAndQuery(): void
    {
        $link = Html::link('/communities/a"b', '<Freifunk>', ['q' => 'a&b', 'empty' => '', 'none' => null, 'page' => 2]);

        $this->assertSame('<a href="/communities/a&quot;b?q=a%26b&amp;page=2">&lt;Freifunk&gt;</a>', $link->html);
        $this->assertSame('<a href="/x"><b>bold</b></a>', Html::link('/x', new Safe('<b>bold</b>'))->html);
    }

    public function testTableEscapesEverythingButSafeCells(): void
    {
        $html = Html::table(['name', new Safe('<i>n</i>')], [['<script>', new Safe('<a>ok</a>')], [null, 3]])->html;

        $this->assertStringContainsString('<th>name</th><th><i>n</i></th>', $html);
        $this->assertStringContainsString('<td>&lt;script&gt;</td><td><a>ok</a></td>', $html);
        $this->assertStringContainsString('<td><span class="muted">-</span></td><td>3</td>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testEmptyTableSaysSo(): void
    {
        $this->assertSame('<p class="muted">Nothing here.</p>', Html::table(['a'], [], 'Nothing here.')->html);
    }

    public function testDefinitions(): void
    {
        $this->assertSame('<dl><dt>a&lt;</dt><dd>b&gt;</dd><dt>c</dt><dd><span class="muted">-</span></dd></dl>', Html::definitions(['a<' => 'b>', 'c' => null])->html);
    }

    public function testTimesAreShownInTheConfiguredTimeZone(): void
    {
        $this->assertSame('2026-10-29 13:00', Html::time('2026-10-29T12:00:00+00:00', 'Europe/Berlin'));
        $this->assertSame('2026-10-29 08:00', Html::time('2026-10-29T12:00:00+00:00', 'America/New_York'));
        $this->assertSame('', Html::time(null));
        $this->assertSame('not a time', Html::time('not a time'));
    }

    public function testDurationsAndNumbers(): void
    {
        $this->assertSame('21.9 s', Html::duration(21.93));
        $this->assertSame('3 min 07 s', Html::duration(187));
        $this->assertSame('', Html::duration(null));
        $this->assertSame('24 941', Html::number(24941));
        $this->assertSame('', Html::number('x'));
    }

    public function testBadge(): void
    {
        $this->assertSame('<span class="badge no-data">no-data</span>', Html::badge('no-data')->html);
        $this->assertSame('<span class="badge run-ok">ok</span>', Html::badge('ok', 'run-ok')->html);
        $this->assertSame('<span class="badge x">&lt;b&gt;</span>', Html::badge('<b>', 'x')->html);
    }

    public function testSparklineNeedsTwoValues(): void
    {
        $this->assertSame('', Svg::sparkline([5])->html);
        $this->assertStringContainsString('<polyline', Svg::sparkline([1, 3, 2])->html);
    }

    public function testSparklineScalesToTheBox(): void
    {
        $html = Svg::sparkline([0, 10], 100, 20)->html;

        // lowest value at the bottom, highest at the top, padding 2
        $this->assertStringContainsString('points="2.0,18.0 98.0,2.0"', $html);
    }

    public function testFlatDataIsDrawnInTheMiddle(): void
    {
        $this->assertStringContainsString('points="2.0,14.0 118.0,14.0"', Svg::sparkline([5, 5], 120, 28)->html);
    }

    public function testChartShowsRangeAndEscapesItsLabel(): void
    {
        $html = Svg::chart([100, 250, 200], 'Nodes <per> run')->html;

        $this->assertStringContainsString('Nodes &lt;per&gt; run', $html);
        $this->assertStringContainsString('(100 … 250 over the last 3 runs)', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('not enough runs', Svg::chart([1], 'x')->html);
    }
}
