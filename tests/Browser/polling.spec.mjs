import { errors } from '@playwright/test';
import { test, expect } from './workbench.mjs';

const isPoll = request => request.method() === 'POST'
    && request.headers()['content-type']?.includes('application/json')
    && request.postDataJSON()?.components?.some(component => component.calls?.some(call => call.method === 'pollJobFeedback'));

async function poll(page) {
    await page.bringToFront();
    const response = await page.waitForResponse(response => isPoll(response.request()));
    expect(response.ok()).toBe(true);
}

async function noPoll(page, interval = 350) {
    await page.bringToFront();
    await expect(page.waitForRequest(isPoll, { timeout: interval * 3 })).rejects.toThrow(errors.TimeoutError);
}

async function confirm(page, action) {
    await action.click();
    await page.getByRole('button', { name: 'Confirm', exact: true }).click();
}

async function login(page, url, id) {
    await page.goto(`${url}/_workbench/login/${id}`);
    const identity = await page.request.get(`${url}/_workbench/user`);
    expect(identity.ok()).toBe(true);
    expect((await identity.json()).id).toBe(id);
}

async function openGlobalMaintenance(page) {
    const sidebar = page.getByRole('navigation', { name: 'Sidebar navigation' });
    await expect(sidebar.getByText('Job Feedback Examples', { exact: true })).toBeVisible();
    await sidebar.getByRole('link', { name: 'Global maintenance', exact: true }).click();
    await expect(page).toHaveURL(/\/workbench\/global-maintenance$/);
}

const rebuild = page => page.getByRole('button', { name: 'Rebuild search index', exact: true });
const generateReport = page => page.getByRole('button', { name: 'Generate report', exact: true }).first();
const status = (page, message) => page.getByRole('status').getByText(message, { exact: true });
const countWords = page => page.getByRole('button', { name: 'Count words', exact: true });
const fastPoll = 'wire:poll.350ms';
const feedbackPoller = page => page.locator(`[${fastPoll.replace(/[.:]/g, '\\$&')}="pollJobFeedback"]`);
const spinner = action => action.locator('.fi-loading-indicator:visible');

test('polls only while a job is active and refreshes the form without losing edits', async ({ page, workbench }) => {
    await page.goto(`${workbench.url}/workbench/documents/1/edit`);
    const action = countWords(page);
    const title = page.getByRole('textbox', { name: 'Title' });
    const count = page.getByRole('textbox', { name: 'Word count' });
    await expect(count).toHaveValue('');
    await expect(spinner(action)).toHaveCount(0);
    await noPoll(page);

    await title.fill('Unsaved browser edit');
    await confirm(page, action);
    await expect(action).toBeDisabled();
    await expect(action).toHaveAttribute('aria-busy', 'true');
    await expect(spinner(action)).toHaveCount(1);
    await poll(page);
    await expect(spinner(action)).toHaveCount(1);
    expect(workbench.queued()).toBe(1);
    await workbench.workOnce();

    await expect(count).toHaveValue('9');
    await expect(title).toHaveValue('Unsaved browser edit');
    await expect(action).toBeEnabled();
    await expect(action).not.toHaveAttribute('aria-busy');
    await expect(spinner(action)).toHaveCount(0);
    await noPoll(page);
});

test('keeps polling after retry and stops on terminal failure', async ({ page, workbench }) => {
    await login(page, workbench.url, 1);
    await page.goto(`${workbench.url}/workbench/report-jobs`);
    const action = generateReport(page);
    await action.click();
    await page.getByText('Demonstrate retry and failure', { exact: true }).click();
    await page.getByRole('button', { name: 'Submit', exact: true }).click();
    await expect(action).toBeDisabled();

    await workbench.workOnce();
    await expect(status(page, 'Job waiting to retry.')).toBeVisible();
    await expect(action).toBeDisabled();
    await expect(spinner(action)).toHaveCount(1);
    await poll(page);
    expect(workbench.queued()).toBe(1);
    await workbench.workOnce();

    await expect(status(page, 'Job failed.')).toBeVisible();
    await expect(action).toBeEnabled();
    await expect(spinner(action)).toHaveCount(0);
    await noPoll(page, 750);
});

test.describe('with a stale claim', () => {
    test.use({ recovery: true });

    test('delivers one completion toast when a row replaces a stale bulk run', async ({ page, workbench }) => {
        await login(page, workbench.url, 1);
        await page.goto(`${workbench.url}/workbench/documents`);
        const first = page.getByRole('row', { name: /Getting started/ });
        const second = page.getByRole('row', { name: /Release notes/ });
        const bulk = page.getByRole('button', { name: /^Simulate lost updates for selected documents/ });
        const rowAction = row => row.getByRole('button', { name: /^Simulate lost update/ });
        await first.getByRole('checkbox').check();
        await second.getByRole('checkbox').check();
        await bulk.click();
        await page.getByRole('dialog', { name: /Simulate lost updates for selected documents$/ })
            .getByRole('button', { name: 'Submit', exact: true }).click();
        await expect(bulk).toContainText('Stale');
        await expect(rowAction(first)).toContainText('Stale');
        await expect(rowAction(second)).toContainText('Stale');

        await rowAction(first).click();
        const modal = page.getByRole('dialog', { name: /Simulate lost update$/ });
        await modal.getByRole('switch', { name: 'Drop the completion updates' }).uncheck();
        await modal.getByRole('button', { name: 'Start a new run' }).click();
        const completed = page.getByText('Demo job completed for Getting started', { exact: true });
        await expect(completed.first()).toBeVisible();
        await poll(page);
        await expect(completed).toHaveCount(1);
        await expect(spinner(rowAction(first))).toHaveCount(0);
        await expect(spinner(rowAction(second))).toHaveCount(1);
        await expect(spinner(bulk)).toHaveCount(1);
    });

    test('runs and recovers the lost-update simulation from a document row', async ({ page, workbench }) => {
        await login(page, workbench.url, 1);
        await page.goto(`${workbench.url}/workbench/documents`);
        const simulate = title => page.getByRole('row', { name: title }).getByRole('button', { name: /^Simulate lost update/ });
        const action = simulate(/Getting started/);
        const other = simulate(/Release notes/);
        await expect(simulate(/Draft announcement/)).toHaveCount(0);
        await action.click();

        const modal = page.getByRole('dialog', { name: /Simulate lost update$/ });
        await modal.getByRole('button', { name: 'Submit', exact: true }).click();
        await expect(spinner(action)).toHaveCount(1);
        await expect(spinner(other)).toHaveCount(0);
        await expect(action).toContainText('Stale');
        await expect(action).toBeEnabled();
        await expect(spinner(action)).toHaveCount(1);

        await action.click();
        await expect(modal.getByRole('note')).toContainText('Replacing a stale run');
        await modal.getByRole('switch', { name: 'Drop the completion updates' }).uncheck();
        await modal.getByRole('button', { name: 'Start a new run' }).click();
        await expect(page.getByText('Demo job completed for Getting started', { exact: true })).toBeVisible();
        await expect(action).not.toContainText('Stale');
        await expect(spinner(action)).toHaveCount(0);
    });

    test('recovers two stale document runs through the Workbench bulk action', async ({ page, workbench }) => {
        await login(page, workbench.url, 1);
        await page.goto(`${workbench.url}/workbench/documents`);
        const first = page.getByRole('row', { name: /Getting started/ }).getByRole('checkbox');
        const second = page.getByRole('row', { name: /Release notes/ }).getByRole('checkbox');
        const draft = page.getByRole('row', { name: /Draft announcement/ }).getByRole('checkbox');
        const action = page.getByRole('button', { name: /^Simulate lost updates for selected documents/ });
        await first.check();
        await second.check();
        await draft.check();
        await action.click();
        const modal = page.getByRole('dialog', { name: /Simulate lost updates for selected documents$/ });
        const summary = page.getByText('2 of 2 job requests accepted', { exact: true });
        await modal.getByRole('button', { name: 'Submit', exact: true }).click();
        await expect(page.getByText('2 of 3 job requests accepted', { exact: true })).toBeVisible();
        await expect(page.getByText('1 selected record is not authorized for this action.', { exact: true })).toBeVisible();
        const stale = status(page, 'No recent update. The original job may still run or retry.');
        await expect(stale).toHaveCount(2);
        await expect(action).toContainText('Stale');
        await expect(spinner(action)).toHaveCount(1);
        const warning = page.getByText('Bulk job runs may be stale', { exact: true });
        await expect(warning).toBeVisible();
        await poll(page);
        await expect(warning).toHaveCount(1);
        await expect(page.getByText('Job run may be stale', { exact: true })).toHaveCount(0);
        await expect(page.getByText(/^Demo job completed for /)).toHaveCount(0);

        await second.uncheck();
        await action.click();
        await expect(modal.getByRole('note')).toContainText('Replacing a stale run');
        await modal.getByRole('switch', { name: 'Drop the completion updates' }).uncheck();
        await modal.getByRole('button', { name: 'Start a new run' }).click();
        await expect(summary).toBeVisible();
        await expect(status(page, 'Job completed.')).toHaveCount(2);
        await expect(page.getByText('Demo job completed for Getting started', { exact: true })).toBeVisible();
        await expect(page.getByText('Demo job completed for Release notes', { exact: true })).toBeVisible();
        await expect(page.getByText('Demo job completed for Draft announcement', { exact: true })).toHaveCount(0);
        await expect(stale).toHaveCount(0);
        await expect(action).not.toContainText('Stale');
        await expect(second).not.toBeChecked();
    });

    test('reloads an already stale action without waiting for polling or replaying the toast', async ({ page, workbench }) => {
        await login(page, workbench.url, 1);
        await openGlobalMaintenance(page);
        const simulate = page.getByRole('button', { name: /^Simulate a lost update/ });
        const toast = page.getByText('Job run may be stale', { exact: true });
        await simulate.click();
        const modal = page.getByRole('dialog', { name: /Simulate a lost update$/ });
        await modal.getByRole('button', { name: 'Submit', exact: true }).click();
        await expect(toast).toBeVisible();

        let releasePoll;
        const holdPoll = new Promise(resolve => { releasePoll = resolve; });
        await page.route('**/*', async route => {
            if (isPoll(route.request())) {
                await holdPoll;
            }
            await route.continue();
        });

        try {
            await page.reload();
            await expect(simulate).toContainText('Stale');
            await expect(simulate).toBeEnabled({ timeout: 1000 });
        } finally {
            releasePoll();
        }

        await poll(page);
        await poll(page);
        await expect(toast).toHaveCount(0);
    });

    test('keeps the recovery form cancel button enabled during a poll', async ({ page, workbench }) => {
        await login(page, workbench.url, 1);
        await openGlobalMaintenance(page);
        const simulate = page.getByRole('button', { name: /^Simulate a lost update/ });
        await simulate.click();
        const modal = page.getByRole('dialog', { name: /Simulate a lost update$/ });
        await expect(modal.getByRole('note')).toHaveCount(0);
        await modal.getByRole('button', { name: 'Submit', exact: true }).click();
        await expect(simulate).toContainText('Stale');
        await expect(page.getByText('Job run may be stale', { exact: true })).toBeVisible();
        await simulate.click();
        const cancel = modal.getByRole('button', { name: 'Keep waiting' });
        await expect(cancel).toBeVisible();
        const warning = modal.getByRole('note');
        await expect(warning).toContainText('Replacing a stale run');
        await expect(warning).toContainText('Starting a new run does not cancel the original job.');
        await expect(warning).toHaveCSS('border-inline-start-width', '3px');
        // Livewire polls can restore the banner's inline style between assertions.
        await page.addStyleTag({
            content: '[role="note"] { --warning-700: rgb(160, 90, 0); --warning-300: rgb(160, 90, 0); }',
        });
        await expect(warning.getByText('Replacing a stale run', { exact: true })).toHaveCSS('color', 'rgb(160, 90, 0)');
        await expect(warning.locator('svg')).toHaveCSS('color', 'rgb(160, 90, 0)');
        await expect(warning.getByText(/Starting a new run does not cancel the original job/)).not.toHaveCSS('color', 'rgb(160, 90, 0)');
        const title = modal.getByText('Simulate a lost update', { exact: true });
        await expect.poll(async () => {
            const [bannerBox, titleBox] = await Promise.all([warning.boundingBox(), title.boundingBox()]);
            return bannerBox !== null && titleBox !== null && bannerBox.y + bannerBox.height < titleBox.y;
        }).toBe(true);

        let releasePoll;
        let pollIntercepted;
        const holdPoll = new Promise(resolve => { releasePoll = resolve; });
        const polling = new Promise(resolve => { pollIntercepted = resolve; });
        await page.route('**/*', async route => {
            if (isPoll(route.request())) {
                pollIntercepted();
                await holdPoll;
            }
            await route.continue();
        });

        try {
            await polling;
            await expect(cancel).toBeEnabled({ timeout: 1000 });
            await expect(cancel).not.toHaveClass(/\bfi-disabled\b/);
            await cancel.click();
        } finally {
            releasePoll();
        }

        await expect(modal).toBeHidden();
        await expect(simulate).toContainText('Stale');
    });

    test('opens the confirmation, keeps waiting on cancel, and starts only a confirmed replacement', async ({ page, workbench }) => {
        const badge = /\u{26A0}\u{FE0E}\s+Stale/u;
        await login(page, workbench.url, 1);
        await openGlobalMaintenance(page);
        await confirm(page, rebuild(page));
        await expect(status(page, 'Job queued.')).toBeVisible();
        await expect(rebuild(page).getByText(badge)).toHaveCount(0);
        const stopWorker = workbench.startWorker();
        await expect(status(page, 'Job running.')).toBeVisible();
        await stopWorker();

        const replacement = page.getByRole('button', { name: /^Rebuild search index/ }).filter({ hasText: badge });
        const modal = page.getByRole('alertdialog', { name: /Rebuild search index$/ });
        await expect(status(page, 'No recent update. The original job may still run or retry.')).toBeVisible();
        await expect(replacement.getByText(badge)).toBeVisible();
        await expect(spinner(replacement)).toHaveCount(1);
        await expect(replacement).toBeEnabled();
        await replacement.click();
        await expect(modal.getByText(/Starting a new run does not cancel the original job/)).toBeVisible();
        await modal.getByRole('button', { name: 'Keep waiting' }).click();
        await expect(modal).toBeHidden();
        expect(workbench.queued()).toBe(1);

        await replacement.click();
        await modal.getByRole('button', { name: 'Start a new run' }).click();
        await expect(status(page, 'Job queued.')).toBeVisible();
        await expect(rebuild(page)).toBeDisabled();
        await expect(rebuild(page)).toHaveAttribute('aria-busy', 'true');
        await expect(rebuild(page).getByText(badge)).toHaveCount(0);
        await expect(spinner(rebuild(page))).toHaveCount(1);
        await expect(status(page, 'No longer the active run. Outcome unknown.')).toBeVisible();
        expect(workbench.queued()).toBe(2);
    });
});

test('stops polling in one tab while an independent job in another tab keeps polling', async ({ context, page, workbench }) => {
    await login(page, workbench.url, 1);
    const second = await context.newPage();

    for (const tab of [page, second]) {
        await tab.goto(`${workbench.url}/workbench/report-jobs`);
        await generateReport(tab).click();
        await tab.getByRole('button', { name: 'Submit', exact: true }).click();
        await expect(generateReport(tab)).toBeDisabled();
        await poll(tab);
    }

    expect(workbench.queued()).toBe(2);
    await page.bringToFront();
    await workbench.workOnce();

    await expect(status(page, 'Job completed.')).toBeVisible();
    await expect(generateReport(page)).toBeEnabled();
    await noPoll(page, 750);
    await poll(second);
    await expect(status(second, 'Job queued.')).toBeVisible();
    await expect(status(second, 'Job completed.')).toHaveCount(0);
    await expect(generateReport(second)).toBeDisabled();

    await workbench.workOnce();
    await expect(status(second, 'Job completed.')).toBeVisible();
    await expect(generateReport(second)).toBeEnabled();
    await noPoll(second, 750);
});

test('shares one job between same-session tabs and separate authorized users', async ({ browser, context, page, workbench }) => {
    const other = await browser.newContext();
    try {
        await login(page, workbench.url, 1);
        await expect(page.getByRole('heading', { name: 'Dashboard', exact: true })).toBeVisible();
        await openGlobalMaintenance(page);
        const sameSession = await context.newPage();
        await sameSession.goto(`${workbench.url}/workbench/global-maintenance`);
        const otherUser = await other.newPage();
        await login(otherUser, workbench.url, 2);
        await openGlobalMaintenance(otherUser);

        await confirm(page, rebuild(page));
        await expect(rebuild(page)).toBeDisabled();

        for (const observer of [sameSession, otherUser]) {
            await noPoll(observer);
            await expect(rebuild(observer)).toBeEnabled();
            await observer.reload();
            await expect(rebuild(observer)).toBeDisabled();
            await poll(observer);
        }
        expect(workbench.queued()).toBe(1);
        await page.bringToFront();
        await workbench.workOnce();

        for (const observer of [page, sameSession, otherUser]) {
            await observer.bringToFront();
            await expect(status(observer, 'Job completed.')).toBeVisible();
            await expect(observer.getByRole('listitem').getByText('Job completed.', { exact: true })).toHaveCount(1);
            await expect(rebuild(observer)).toBeEnabled();
            await noPoll(observer);
        }
    } finally {
        await other.close();
    }
});

test.describe('with overlapping submissions', () => {
    test.use({ concurrentSubmissions: true });

    test('deduplicates two authorized submissions that race to claim the same job', async ({ browser, page, workbench }) => {
        const otherContext = await browser.newContext();
        try {
            const other = await otherContext.newPage();
            await login(page, workbench.url, 1);
            await login(other, workbench.peerUrl, 2);
            await openGlobalMaintenance(page);
            await openGlobalMaintenance(other);
            for (const tab of [page, other]) {
                await rebuild(tab).click();
                await expect(tab.getByRole('button', { name: 'Confirm', exact: true })).toBeVisible();
            }

            await page.getByRole('button', { name: 'Confirm', exact: true }).click();
            await other.getByRole('button', { name: 'Confirm', exact: true }).click();
            await expect.poll(async () => Promise.all([
                workbench.submissionReady('first'),
                workbench.submissionReady('second'),
            ])).toEqual([true, true]);
            expect(workbench.queued()).toBe(0);

            await workbench.releaseSubmissions();
            for (const tab of [page, other]) {
                await tab.bringToFront();
                await expect(rebuild(tab)).toBeDisabled();
                await expect(status(tab, 'Job queued.')).toBeVisible();
                await poll(tab);
            }
            expect(workbench.queued()).toBe(1);
            await workbench.workOnce();

            for (const tab of [page, other]) {
                await tab.bringToFront();
                await expect(status(tab, 'Job completed.')).toBeVisible();
                await expect(tab.getByRole('listitem').getByText('Job completed.', { exact: true })).toHaveCount(1);
                await expect(rebuild(tab)).toBeEnabled();
                await noPoll(tab);
            }
            expect(workbench.queued()).toBe(0);
        } finally {
            await workbench.releaseSubmissions();
            await otherContext.close();
        }
    });
});

test.describe('with idle discovery enabled', () => {
    test.use({ discovery: true });

    test('discovers shared work without a reload and keeps polling only for authorized users', async ({ browser, page, workbench }) => {
        const observerContext = await browser.newContext();
        const viewerContext = await browser.newContext();
        try {
            await login(page, workbench.url, 1);
            await openGlobalMaintenance(page);
            const observer = await observerContext.newPage();
            await login(observer, workbench.url, 2);
            await openGlobalMaintenance(observer);
            const viewer = await viewerContext.newPage();
            await login(viewer, workbench.url, 3);
            await openGlobalMaintenance(viewer);

            await noPoll(viewer);
            await poll(observer);
            await page.bringToFront();
            await confirm(page, rebuild(page));
            await expect(rebuild(page)).toBeDisabled();
            await observer.bringToFront();
            await expect(rebuild(observer)).toBeDisabled();
            await expect(status(observer, 'Job queued.')).toBeVisible();
            await noPoll(viewer);
            await expect(status(viewer, 'Job queued.')).toHaveCount(0);
            expect(workbench.queued()).toBe(1);

            await observer.bringToFront();
            await workbench.workOnce();
            await expect(status(observer, 'Job completed.')).toBeVisible();
            await expect(rebuild(observer)).toBeEnabled();
            await poll(observer);
            await expect(observer.getByRole('listitem').getByText('Job completed.', { exact: true })).toHaveCount(1);
        } finally {
            await observerContext.close();
            await viewerContext.close();
        }
    });
});

test('shares one host poller across bulk and independent row runs', async ({ page, workbench }) => {
    await page.goto(`${workbench.url}/workbench/documents`);
    const first = page.getByRole('row', { name: /Getting started/ });
    const second = page.getByRole('row', { name: /Release notes/ });
    const firstAction = countWords(first);
    const secondAction = countWords(second);
    const thirdAction = countWords(page.getByRole('row', { name: /Support handbook/ }));
    const bulkAction = page.getByRole('button', { name: 'Count words for selected documents', exact: true });

    await confirm(page, firstAction);
    await expect(firstAction).toBeDisabled();
    await expect(spinner(firstAction)).toHaveCount(1);
    await expect(firstAction).not.toHaveAttribute(fastPoll);
    await expect(feedbackPoller(page)).toHaveCount(1);
    await expect(secondAction).toBeEnabled();
    await expect(spinner(secondAction)).toHaveCount(0);
    await first.getByRole('checkbox').check();
    await second.getByRole('checkbox').check();
    await confirm(page, bulkAction);
    await expect(secondAction).toBeDisabled();
    await expect(first.getByRole('checkbox')).toBeChecked();
    await expect(second.getByRole('checkbox')).toBeChecked();
    await expect(spinner(secondAction)).toHaveCount(1);
    await expect(spinner(bulkAction)).toHaveCount(1);
    await expect(bulkAction).not.toHaveAttribute(fastPoll);
    await expect(feedbackPoller(page)).toHaveCount(1);
    expect(workbench.queued()).toBe(2);

    await confirm(page, thirdAction);
    await expect(spinner(thirdAction)).toHaveCount(1);
    await expect(thirdAction).not.toHaveAttribute(fastPoll);
    await expect(feedbackPoller(page)).toHaveCount(1);
    expect(workbench.queued()).toBe(3);
    await poll(page);

    await workbench.workOnce();
    await expect(firstAction).toBeEnabled();
    await expect(spinner(firstAction)).toHaveCount(0);
    await expect(firstAction).not.toHaveAttribute(fastPoll);
    await expect(secondAction).toBeDisabled();
    await expect(spinner(secondAction)).toHaveCount(1);
    await expect(secondAction).not.toHaveAttribute(fastPoll);
    await expect(feedbackPoller(page)).toHaveCount(1);
    await poll(page);
    expect(workbench.queued()).toBe(2);
    await expect(first.getByRole('checkbox')).toBeChecked();
    await expect(second.getByRole('checkbox')).toBeChecked();

    await workbench.workOnce();
    await expect(secondAction).toBeEnabled();
    await expect(spinner(secondAction)).toHaveCount(0);
    await expect(spinner(bulkAction)).toHaveCount(0);
    await expect(secondAction).not.toHaveAttribute(fastPoll);
    await expect(page.getByText('Word count updated for Release notes', { exact: true })).toHaveCount(1);
    await expect(spinner(thirdAction)).toHaveCount(1);
    await expect(feedbackPoller(page)).toHaveCount(1);

    await workbench.workOnce();
    await expect(thirdAction).toBeEnabled();
    await expect(spinner(thirdAction)).toHaveCount(0);
    await expect(feedbackPoller(page)).toHaveCount(0);
    await noPoll(page);
    await expect(first.getByRole('checkbox')).toBeChecked();
    await expect(second.getByRole('checkbox')).toBeChecked();
});
