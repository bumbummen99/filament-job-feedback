import { test as base, expect } from '@playwright/test';
import { execFileSync, spawn } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { once } from 'node:events';
import { cp, mkdir, mkdtemp, readFile, rm, stat, symlink, writeFile } from 'node:fs/promises';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { DatabaseSync } from 'node:sqlite';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../..', import.meta.url));
const skeleton = join(root, 'vendor/orchestra/testbench-core/laravel');

async function unusedPort() {
    const socket = createServer().listen(0, '127.0.0.1');
    await once(socket, 'listening');
    const { port } = socket.address();
    socket.close();
    await once(socket, 'close');
    return port;
}

export const test = base.extend({
    discovery: [false, { option: true }],
    concurrentSubmissions: [false, { option: true }],
    recovery: [false, { option: true }],
    workbench: async ({ discovery, concurrentSubmissions, recovery }, use, testInfo) => {
        const workspace = await mkdtemp(join(tmpdir(), 'job-feedback-browser-'));
        const app = join(workspace, 'laravel');
        const database = join(app, 'database/database.sqlite');
        const servers = [];
        let log = '';
        try {
            for (const entry of ['app', 'bootstrap/autoload.php', 'bootstrap/providers.php', 'composer.json', 'config', 'migrations', 'public/index.php', 'routes', 'server.php']) {
                await cp(join(skeleton, entry), join(app, entry), { recursive: true });
            }
            for (const directory of ['bootstrap/cache', 'database', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs']) {
                await mkdir(join(app, directory), { recursive: true });
            }
            await cp(join(root, 'tests/Browser/bootstrap.php'), join(app, 'bootstrap/app.php'));
            await cp(join(root, 'tests/Browser/config.php'), join(app, 'config/workbench-browser.php'));
            await symlink(join(root, 'vendor'), join(workspace, 'vendor'), 'junction');
            await symlink(join(root, 'vendor'), join(app, 'vendor'), 'junction');
            await symlink(join(root, 'workbench'), join(workspace, 'workbench'), 'junction');
            await cp(join(root, 'composer.json'), join(workspace, 'composer.json'));
            const config = await readFile(join(root, 'testbench.yaml'), 'utf8');
            const privateConfig = config.replace(/^laravel:.*$/m, `laravel: ${JSON.stringify(app)}`);
            if (privateConfig === config) {
                throw new Error('Unable to set a private Laravel path in testbench.yaml.');
            }
            await writeFile(join(workspace, 'testbench.yaml'), privateConfig);
            await writeFile(database, '');
            await writeFile(join(app, '.env'), '');

            const port = await unusedPort();
            const url = `http://127.0.0.1:${port}`;
            const env = {
                ...process.env,
                APP_BASE_PATH: app,
                TESTBENCH_WORKING_PATH: workspace,
                APP_ENV: 'local',
                APP_DEBUG: 'true',
                APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
                APP_URL: url,
                AUTH_MODEL: 'Workbench\\App\\Models\\User',
                CACHE_STORE: 'file',
                DB_CONNECTION: 'sqlite',
                DB_DATABASE: database,
                DB_URL: '',
                FILAMENT_JOB_FEEDBACK_CACHE_STORE: 'file',
                LOG_CHANNEL: 'single',
                QUEUE_CONNECTION: 'database',
                QUEUE_FAILED_DRIVER: 'null',
                SESSION_DRIVER: 'file',
                WORKBENCH_BROWSER_DISCOVERY: String(discovery),
                WORKBENCH_BROWSER_RECOVERY: String(recovery),
            };
            const artisan = (...args) => {
                try {
                    return execFileSync('php', ['vendor/bin/testbench', ...args, '--no-interaction'], {
                        cwd: workspace, env, encoding: 'utf8', timeout: 20_000,
                    });
                } catch (error) {
                    throw new Error(`Testbench ${args.join(' ')} failed:\n${error.stdout ?? ''}${error.stderr ?? ''}`, { cause: error });
                }
            };

            artisan('migrate');
            artisan('db:seed', '--class=Workbench\\Database\\Seeders\\DatabaseSeeder');
            artisan('db:seed', '--class=SkyRaptor\\FilamentJobFeedback\\Tests\\Fixtures\\BrowserSeeder');
            artisan('filament:assets');
            const startServer = async (serverPort, submissionSlot = '') => {
                const serverUrl = `http://127.0.0.1:${serverPort}`;
                const server = spawn('php', ['-S', `127.0.0.1:${serverPort}`, join(app, 'server.php')], {
                    cwd: join(app, 'public'),
                    env: { ...env, APP_URL: serverUrl, WORKBENCH_BROWSER_SUBMISSION_SLOT: submissionSlot },
                });
                servers.push(server);
                for (const stream of [server.stdout, server.stderr]) {
                    stream.on('data', chunk => { log += chunk; });
                }
                await expect.poll(async () => {
                    try {
                        const response = await fetch(`${serverUrl}/workbench`, {
                            signal: AbortSignal.timeout(1000),
                            headers: { Accept: 'application/json' },
                        });
                        return response.ok ? response.status : `${response.status}: ${(await response.text()).slice(0, 400)}`;
                    } catch (error) {
                        if (!(error instanceof TypeError) && error.name !== 'TimeoutError') {
                            throw error;
                        }
                        return 'not listening';
                    }
                }, { timeout: 7000 }).toBe(200).catch(error => {
                    throw new Error(`${error.message}\n${log.slice(-6000)}`, { cause: error });
                });
                return serverUrl;
            };
            await startServer(port, concurrentSubmissions ? 'first' : '');
            // Each PHP development server handles one request at a time.
            const peerUrl = concurrentSubmissions ? await startServer(await unusedPort(), 'second') : null;

            const inspectQueue = query => {
                const connection = new DatabaseSync(database, { readOnly: true });
                try {
                    return connection.prepare(query).get().count;
                } finally {
                    connection.close();
                }
            };

            await use({
                url,
                peerUrl,
                submissionReady: async slot => {
                    try {
                        return (await stat(join(app, `storage/browser-submission-${slot}`))).isFile();
                    } catch (error) {
                        if (error.code !== 'ENOENT') throw error;
                        return false;
                    }
                },
                releaseSubmissions: () => writeFile(join(app, 'storage/browser-release-submissions'), ''),
                workOnce: async () => {
                    await expect.poll(
                        () => inspectQueue("select count(*) as count from jobs where queue = 'default' and reserved_at is null and available_at <= unixepoch()"),
                        { timeout: 5000, message: 'Expected a job to become available on the Workbench queue' },
                    ).toBeGreaterThan(0);

                    artisan('queue:work', '--once', '--sleep=0', '--no-ansi');
                },
                startWorker: () => {
                    const worker = spawn('php', ['vendor/bin/testbench', 'queue:work', '--once', '--sleep=0', '--no-interaction'], {
                        cwd: workspace, env, stdio: ['ignore', 'pipe', 'pipe'],
                    });
                    servers.push(worker);
                    worker.stdout.on('data', chunk => { log += chunk; });
                    worker.stderr.on('data', chunk => { log += chunk; });

                    return async () => {
                        if (worker.exitCode === null && worker.signalCode === null) {
                            const exited = once(worker, 'close');
                            worker.kill('SIGKILL');
                            await exited;
                        }
                    };
                },
                queued: () => inspectQueue('select count(*) as count from jobs'),
            });
        } finally {
            await Promise.all(servers.filter(server => server.exitCode === null && server.signalCode === null).map(async server => {
                const exited = once(server, 'close');
                server.kill();
                await exited;
            }));
            if (log && testInfo.status !== 'passed') {
                await testInfo.attach('workbench-server', { body: log, contentType: 'text/plain' });
                const laravelLog = await readFile(join(app, 'storage/logs/laravel.log'), 'utf8').catch(error => {
                    if (error.code !== 'ENOENT') throw error;
                    return '';
                });
                if (laravelLog) {
                    await testInfo.attach('workbench-laravel', { body: laravelLog, contentType: 'text/plain' });
                }
            }
            await rm(workspace, { recursive: true, force: true });
        }
    },
});

export { expect };
