import { test, expect } from './helpers/test.js';

/**
 * Sprawl's intrusion (LUMN-103, criterion 7): on hover or keyboard focus, a link or a button
 * shudders once — never in a loop — and the focus ring stays drawn through the burst and after it.
 *
 * The effect is CSS only (resources/css/scss/_effects.scss). What the browser does with it is
 * recorded from its own animation events, so "once" is counted, not assumed.
 */

const INTRUSION = 'sprawl-intrusion';

/** Long enough for a looping 200 ms burst to have started again twice. */
const WAIT = 500;

async function open(page, theme) {
    await page.addInitScript(
        ({ chosen, name }) => {
            sessionStorage.setItem('boot_sequence_played', '1');
            localStorage.setItem('theme', chosen);

            // Every start, repeat and end of the intrusion, with the element it played on. When
            // window.holdIntrusion names a selector, the burst on a matching element is paused as
            // it starts, so that a test can look at the page in the middle of it.
            window.intrusions = [];
            for (const type of ['animationstart', 'animationiteration', 'animationend']) {
                document.addEventListener(
                    type,
                    (event) => {
                        if (event.animationName !== name) return;
                        const animation = event.target.getAnimations().find((a) => a.animationName === name);
                        const timing = animation?.effect.getTiming();
                        window.intrusions.push({
                            type,
                            target: event.target,
                            duration: timing?.duration,
                            iterations: timing?.iterations,
                        });
                        if (
                            type === 'animationstart' &&
                            window.holdIntrusion &&
                            event.target.matches(window.holdIntrusion)
                        ) {
                            animation.pause();
                            window.heldIntrusion = animation;
                        }
                    },
                    true,
                );
            }
        },
        { chosen: theme, name: INTRUSION },
    );
    await page.goto('/fr/');
    await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
}

/** The recorded events of one type on the element itself. */
const recorded = (locator, type) =>
    locator.evaluate(
        (element, wanted) =>
            window.intrusions
                .filter((record) => record.target === element && record.type === wanted)
                .map(({ duration, iterations }) => ({ duration, iterations })),
        type,
    );

/** Moves the pointer onto the page's margin, away from every control. */
const leave = (page) => page.mouse.move(2, 600);

const navLink = (page) => page.locator('header nav a').first();

test('a link shudders once on hover, never loops, and again on the next approach', async ({ page }) => {
    await open(page, 'sprawl');
    const link = navLink(page);

    await link.hover();
    await expect.poll(() => recorded(link, 'animationstart')).toHaveLength(1);
    const [start] = await recorded(link, 'animationstart');
    expect(start.iterations).toBe(1);
    expect(start.duration).toBeGreaterThanOrEqual(150);
    expect(start.duration).toBeLessThanOrEqual(250);

    // The pointer stays: the burst ends, and nothing starts again.
    await expect.poll(() => recorded(link, 'animationend')).toHaveLength(1);
    await page.waitForTimeout(WAIT);
    expect(await recorded(link, 'animationstart')).toHaveLength(1);
    expect(await recorded(link, 'animationiteration')).toEqual([]);

    await leave(page);
    await link.hover();
    await expect.poll(() => recorded(link, 'animationstart')).toHaveLength(2);
});

for (const [kind, selector] of [
    ['a link styled as a button (.btn)', '#hero-profiles > a'],
    ['a button styled as a button (the theme switcher)', '#theme-switcher > .dropdown > button'],
    ['a footer link', 'footer nav a'],
]) {
    test(`${kind} shudders too`, async ({ page }) => {
        await open(page, 'sprawl');
        const control = page.locator(selector).first();

        await control.hover();
        await expect.poll(() => recorded(control, 'animationstart')).toHaveLength(1);
    });
}

test('a plain button shudders too: a theme in the switcher', async ({ page }) => {
    // Every other button of the public page is a .btn: this one proves `button` itself is covered.
    await open(page, 'sprawl');
    await page.locator('#theme-switcher > .dropdown > button').click();
    const option = page.locator('#theme-switcher li button').first();
    await expect(option).toBeVisible();
    expect(await option.evaluate((element) => element.classList.contains('btn'))).toBe(false);

    await option.hover();
    await expect.poll(() => recorded(option, 'animationstart')).toHaveLength(1);
});

test("nothing loops in a footer link: Néon's hover glitch stays in Néon", async ({ page }) => {
    // The footer's labels carry .glitch-hover, which repeats for as long as the pointer stays.
    await open(page, 'sprawl');
    const link = page.locator('footer nav a').first();

    await link.hover();
    await expect.poll(() => recorded(link, 'animationend')).toHaveLength(1);

    const loops = await link.evaluate((element) =>
        element
            .getAnimations({ subtree: true })
            .filter((animation) => animation.effect.getTiming().iterations === Infinity)
            .map((animation) => animation.animationName ?? 'unnamed'),
    );
    expect(loops).toEqual([]);
});

test("Néon keeps its hover glitch on the footer's labels", async ({ page }) => {
    // Its selector was narrowed to Néon by LUMN-103: this proves the narrowing kept it there.
    await open(page, 'neon-noir');
    const link = page.locator('footer nav a').first();
    const playing = () =>
        link.evaluate((element) =>
            element
                .getAnimations({ subtree: true })
                .filter((animation) => animation.playState === 'running')
                .map((animation) => animation.animationName)
                .filter((name) => name === 'glitch-red' || name === 'glitch-cyan')
                .sort(),
        );

    expect(await playing()).toEqual([]);
    await link.hover();
    await expect.poll(playing).toEqual(['glitch-cyan', 'glitch-red']);
});

test('keyboard focus: the burst plays once, and the focus ring is drawn all through it', async ({ page }) => {
    await open(page, 'sprawl');
    // The one with a transform in its burst — the case that could move or clip the ring.
    const button = page.locator('#hero-profiles > a').first();

    await page.evaluate(() => {
        window.holdIntrusion = '#hero-profiles > a';
    });
    // A key pressed first makes the next focus a keyboard one, as it is when tabbing through.
    await page.keyboard.press('Tab');
    await button.focus();
    expect(await button.evaluate((element) => element.matches(':focus-visible'))).toBe(true);
    await expect.poll(() => page.evaluate(() => window.heldIntrusion !== undefined)).toBe(true);

    const { during, after } = await button.evaluate((element) => {
        const ring = () => {
            const style = getComputedStyle(element);
            return {
                outline: `${style.outlineStyle} ${style.outlineWidth} ${style.outlineColor} ${style.outlineOffset}`,
                clipPath: style.clipPath,
                opacity: style.opacity,
                visibility: style.visibility,
            };
        };

        const animation = window.heldIntrusion;
        const samples = [];
        for (let time = 0; time < 200; time += 25) {
            animation.currentTime = time;
            samples.push({ shadow: getComputedStyle(element).textShadow, ...ring() });
        }
        animation.finish();
        return { during: samples, after: { shadow: getComputedStyle(element).textShadow, ...ring() } };
    });

    // Once the burst is over, the ring is drawn, and nothing hides it.
    const { shadow: restingShadow, ...restingRing } = after;
    expect(restingRing.outline).not.toMatch(/^none /);
    expect(restingRing.outline).not.toMatch(/ 0px /);
    expect(restingRing).toMatchObject({ clipPath: 'none', opacity: '1', visibility: 'visible' });

    // Each sample was taken inside the burst — its colours split — and the ring was the same there.
    expect(during).toHaveLength(8);
    for (const { shadow, ...ring } of during) {
        expect(shadow).not.toBe(restingShadow);
        expect(ring).toEqual(restingRing);
    }

    await page.waitForTimeout(WAIT);
    expect(await recorded(button, 'animationstart')).toHaveLength(1);
    expect(await recorded(button, 'animationiteration')).toEqual([]);
});

// Animations stopped, two ways (LUMN-11): the system's preference, or the visitor's switch. Both
// select the same stopped state in _effects.scss, through two different selectors.
const STOPPED = [
    ['under a reduced-motion preference', { reducedMotion: 'reduce' }, async () => {}],
    [
        'with animations stopped by the switch',
        {},
        (page) => page.addInitScript(() => localStorage.setItem('motion', 'off')),
    ],
];

for (const [name, options, stop] of STOPPED) {
    test.describe(name, () => {
        test.use(options);

        test('the intrusion is a change of colour: nothing plays, nothing moves', async ({ page }) => {
            await stop(page);
            await open(page, 'sprawl');
            const link = navLink(page);
            const resting = await link.evaluate((element) => getComputedStyle(element).textShadow);

            await link.hover();
            await expect
                .poll(() => link.evaluate((element) => getComputedStyle(element).textShadow))
                .not.toBe(resting);

            await page.waitForTimeout(WAIT);
            expect(await recorded(link, 'animationstart')).toEqual([]);
            expect(await link.evaluate((element) => getComputedStyle(element).transform)).toBe('none');
        });

        test("Néon's footer labels do not glitch on hover", async ({ page }) => {
            await stop(page);
            await open(page, 'neon-noir');
            const link = page.locator('footer nav a').first();

            await link.hover();
            await page.waitForTimeout(WAIT);

            const playing = await link.evaluate((element) =>
                element
                    .getAnimations({ subtree: true })
                    .filter((animation) => animation.playState === 'running')
                    .map((animation) => animation.animationName),
            );
            expect(playing).toEqual([]);
        });
    });
}

for (const theme of ['steampunk', 'neon-noir']) {
    test(`${theme} has no intrusion`, async ({ page }) => {
        await open(page, theme);
        const link = navLink(page);

        await link.hover();
        await page.waitForTimeout(WAIT);
        expect(await recorded(link, 'animationstart')).toEqual([]);
    });
}
