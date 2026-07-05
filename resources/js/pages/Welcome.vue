<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import AppLogoIcon from '@/components/AppLogoIcon.vue';
import LandingDashboardPreview from '@/components/landing/LandingDashboardPreview.vue';
import { dashboard, login, register } from '@/routes';

type PageProps = {
    auth?: {
        user?: unknown;
    };
};

type PrimaryCta =
    | { kind: 'inertia'; href: ReturnType<typeof dashboard>; label: string }
    | { kind: 'anchor'; href: string; label: string };

const props = withDefaults(
    defineProps<{
        canRegister: boolean;
    }>(),
    {
        canRegister: true,
    },
);

const page = usePage<PageProps>();
const isSignedIn = computed(() => Boolean(page.props.auth?.user));
const pointerX = ref(52);
const pointerY = ref(18);

// A visitor to an already-claimed instance can't register or sign in to it —
// this instance belongs to whoever set it up first. Point them at the
// install command and setup docs instead of a login page that isn't theirs.
const primaryCta = computed<PrimaryCta>(() => {
    if (isSignedIn.value) {
        return { kind: 'inertia', href: dashboard(), label: 'Open dashboard' };
    }

    if (props.canRegister) {
        return { kind: 'inertia', href: register(), label: 'Start setup' };
    }

    return { kind: 'anchor', href: '#setup', label: 'See setup steps' };
});

// vue-tsc doesn't narrow primaryCta.href across the v-if/v-else element pair
// below, so the plain <a> fallback reads this string-typed sibling instead.
const primaryCtaAnchorHref = computed(() =>
    primaryCta.value.kind === 'anchor' ? primaryCta.value.href : '#setup',
);

const landingStyle = computed(() => ({
    '--pointer-x': `${pointerX.value}%`,
    '--pointer-y': `${pointerY.value}%`,
}));

const featureCards = [
    {
        title: '"Where did that email go?"',
        body: 'Never again. Every send captures the request, SES message ID, recipients, headers, tags, timeline, raw MIME, and a rendered preview on one screen.',
        accent: 'mint',
    },
    {
        title: '"Did DNS actually propagate?"',
        body: 'Add an SES identity, copy DKIM records, re-check DNS with one click, and see exactly what is still missing before production traffic moves.',
        accent: 'blue',
    },
    {
        title: '"Do we have to rewrite our mail code?"',
        body: 'No. Set MAIL_MAILER=larasend and every mailable, notification, and queued send you already have flows through Larasend unchanged.',
        accent: 'violet',
    },
    {
        title: '"Why did our webhook miss that bounce?"',
        body: 'SES events are signature-verified, normalized, retried, and visible with request status, latency, endpoint health, and failure history.',
        accent: 'amber',
    },
];

const repoUrl = 'https://github.com/savvyagents/larasend';

const installCommand =
    'curl -fsSL https://raw.githubusercontent.com/savvyagents/larasend/main/docker-compose.yml -o docker-compose.yml && docker compose up -d';

const installCopied = ref(false);

async function copyInstallCommand(): Promise<void> {
    try {
        await navigator.clipboard.writeText(installCommand);
        installCopied.value = true;
        window.setTimeout(() => {
            installCopied.value = false;
        }, 2000);
    } catch {
        // Clipboard unavailable (non-secure context); the command stays selectable.
    }
}

const setupSteps = [
    [
        '01',
        'Run the Docker stack',
        'Pull the published image, set APP_URL and DB_PASSWORD, then start Larasend with Docker Compose.',
    ],
    [
        '02',
        'Connect Amazon SES',
        'Paste SES credentials for sending, quota reads, identity checks, and event configuration.',
    ],
    [
        '03',
        'Verify your domain',
        'Copy DKIM, SPF, DMARC, and bounce records into Route 53 or your DNS provider.',
    ],
    [
        '04',
        'Create an API key',
        'Reveal the key once, copy it into your Laravel apps, and start sending transactional mail.',
    ],
];

function handlePointerMove(event: PointerEvent): void {
    if (window.matchMedia('(pointer: coarse)').matches) {
        return;
    }

    pointerX.value = Math.round((event.clientX / window.innerWidth) * 100);
    pointerY.value = Math.round((event.clientY / window.innerHeight) * 100);
}

let observer: IntersectionObserver | undefined;

onMounted(() => {
    observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer?.unobserve(entry.target);
                }
            });
        },
        { rootMargin: '0px 0px -12% 0px', threshold: 0.16 },
    );

    document
        .querySelectorAll<HTMLElement>('.reveal')
        .forEach((element) => observer?.observe(element));
});

onBeforeUnmount(() => {
    observer?.disconnect();
});
</script>

<template>
    <Head title="The email dashboard AWS never built">
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link
            rel="preconnect"
            href="https://fonts.gstatic.com"
            crossorigin="anonymous"
        />
        <link
            href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700&family=Geist+Mono:wght@400;500;600&family=Instrument+Serif:ital@0;1&display=swap"
            rel="stylesheet"
        />
    </Head>

    <div
        class="landing"
        :style="landingStyle"
        @pointermove.passive="handlePointerMove"
    >
        <div class="bg-field" aria-hidden="true">
            <div class="grid"></div>
            <div class="grain"></div>
            <div class="spotlight"></div>
            <div class="glow glow-one"></div>
            <div class="glow glow-two"></div>
            <div class="glow glow-three"></div>
            <div class="orbital orbital-one"></div>
            <div class="orbital orbital-two"></div>
        </div>

        <nav class="nav">
            <div class="nav-inner">
                <a class="brand" href="#top" aria-label="Larasend home">
                    <span class="mark"
                        ><AppLogoIcon class="brand-mark-icon"
                    /></span>
                    <span>larasend</span>
                </a>

                <div class="nav-links">
                    <a href="#features">Features</a>
                    <a href="#setup">Setup</a>
                    <a href="#code">Laravel</a>
                    <a href="#open-source">Open source</a>
                </div>

                <div class="nav-cta">
                    <a
                        class="github-pill"
                        href="https://github.com/savvyagents/larasend"
                        target="_blank"
                        rel="noopener"
                    >
                        <span>GitHub</span>
                    </a>
                    <Link
                        v-if="isSignedIn"
                        class="btn ghost"
                        :href="dashboard()"
                        >Dashboard</Link
                    >
                    <template v-else>
                        <Link class="btn ghost" :href="login()">Sign in</Link>
                        <Link
                            v-if="canRegister"
                            class="btn primary"
                            :href="register()"
                        >
                            Start setup
                            <span aria-hidden="true">→</span>
                        </Link>
                    </template>
                </div>
            </div>
        </nav>

        <main id="top">
            <section class="hero container">
                <div class="eyebrow reveal">
                    <span class="pill">open source</span>
                    <span class="pulse-dot"></span>
                    <span>Self-hosted · Powered by your own AWS SES</span>
                </div>

                <h1 class="reveal hero-title">
                    <span class="line">The email dashboard</span>
                    <span class="line accent-line">AWS never built.</span>
                </h1>

                <p class="lede reveal">
                    SES gives you cheap, reliable sending — and nothing else. No
                    dashboard, no delivery timeline, no content previews, no API
                    keys, no suppression list. Larasend is the missing control
                    plane: self-hosted, Laravel-native, and running entirely on
                    your own AWS account.
                </p>

                <div class="install-cmd reveal">
                    <code>{{ installCommand }}</code>
                    <button
                        type="button"
                        class="btn ghost"
                        @click="copyInstallCommand"
                    >
                        {{ installCopied ? 'Copied' : 'Copy' }}
                    </button>
                </div>

                <div class="cta-row reveal">
                    <Link
                        v-if="primaryCta.kind === 'inertia'"
                        class="btn primary big"
                        :href="primaryCta.href"
                    >
                        {{ primaryCta.label }}
                        <span aria-hidden="true">→</span>
                    </Link>
                    <a
                        v-else
                        class="btn primary big"
                        :href="primaryCtaAnchorHref"
                    >
                        {{ primaryCta.label }}
                        <span aria-hidden="true">→</span>
                    </a>
                    <a
                        class="btn big"
                        :href="repoUrl"
                        target="_blank"
                        rel="noopener"
                    >
                        Star on GitHub
                    </a>
                </div>

                <div class="hero-stats reveal">
                    <div style="--stagger: 0ms">
                        <strong>~$0.10</strong>
                        <span
                            >per 1,000 emails — SES pricing, we charge
                            nothing</span
                        >
                    </div>
                    <div style="--stagger: 70ms">
                        <strong>5 min</strong>
                        <span>from docker compose up to dashboard</span>
                    </div>
                    <div style="--stagger: 140ms">
                        <strong>100%</strong>
                        <span>of your email data on your infrastructure</span>
                    </div>
                    <div style="--stagger: 210ms">
                        <strong>MIT</strong>
                        <span>licensed — fork it, audit it, own it</span>
                    </div>
                </div>

                <div
                    class="hero-visual reveal"
                    aria-label="Larasend dashboard preview"
                >
                    <LandingDashboardPreview />
                </div>
            </section>

            <section class="marquee reveal container" aria-label="Use cases">
                <div class="marquee-track">
                    <span>Receipts</span>
                    <span>OTP codes</span>
                    <span>Invoices</span>
                    <span>Product notifications</span>
                    <span>Lifecycle email</span>
                    <span>Webhook audits</span>
                    <span>Suppression lists</span>
                    <span>SES quota</span>
                    <span>DKIM checks</span>
                    <span>Raw MIME</span>
                    <span>Receipts</span>
                    <span>OTP codes</span>
                    <span>Invoices</span>
                    <span>Product notifications</span>
                    <span>Lifecycle email</span>
                    <span>Webhook audits</span>
                    <span>Suppression lists</span>
                    <span>SES quota</span>
                    <span>DKIM checks</span>
                    <span>Raw MIME</span>
                </div>
            </section>

            <section id="features" class="section container">
                <div class="section-head reveal">
                    <span>Built for product teams</span>
                    <h2>Every send visible. Every event accountable.</h2>
                    <p>
                        Larasend gives your team the pieces raw SES does not:
                        API keys, activity, previews, domains, webhook retries,
                        and a Laravel-first integration path.
                    </p>
                </div>

                <div class="features-grid reveal">
                    <article
                        v-for="(feature, index) in featureCards"
                        :key="feature.title"
                        class="feature-card"
                        :class="feature.accent"
                        :style="{ '--card-delay': `${index * 90}ms` }"
                    >
                        <div class="feature-icon"></div>
                        <h3>{{ feature.title }}</h3>
                        <p>{{ feature.body }}</p>
                    </article>
                </div>
            </section>

            <section id="code" class="section two-col reveal container">
                <div class="copy-panel">
                    <div class="section-kicker">Laravel integration</div>
                    <h2>One line in your .env. That's the migration.</h2>
                    <p>
                        No SDK swap. No rewritten mailables. Set
                        <code>MAIL_MAILER=larasend</code> and every
                        <code>Mail::send</code>, notification, and queued
                        mailable you already have flows through Larasend — with
                        full activity tracking on the other side.
                    </p>
                    <ul>
                        <li>Hashed API keys with one-time reveal.</li>
                        <li>Symfony Mailer transport for Laravel mail.</li>
                        <li>Direct client for transactional API sends.</li>
                        <li>
                            Clear failures when SES rejects or throttles mail.
                        </li>
                    </ul>
                </div>

                <div class="code-card reveal">
                    <div class="code-tabs">
                        <span></span>
                        <span>Laravel</span>
                    </div>
                    <pre><code><span class="code-cursor"></span>MAIL_MAILER=larasend
LARASEND_API_KEY=lsk_live_...
LARASEND_ENDPOINT=https://emails.example.com

Mail::to($user)->send(new ReceiptMail($order));

Larasend::emails()->send([
    'from' => 'receipts@example.com',
    'to' => $user->email,
    'subject' => 'Your receipt',
    'html' => view('mail.receipt', compact('order')),
]);</code></pre>
                </div>
            </section>

            <section id="story" class="section reveal container">
                <div class="story-card">
                    <span class="section-kicker">Why we built this</span>
                    <h2>Built out of necessity, not ambition.</h2>
                    <p>
                        We're
                        <a
                            href="https://savvyagents.ai"
                            target="_blank"
                            rel="noopener"
                            >Savvy Agents</a
                        >, a healthcare AI company. We couldn't send
                        patient-adjacent email through a third-party hosted
                        provider — the data had to stay in our own
                        infrastructure. Every "self-hosted email" option we
                        found was a full mail server: MTAs, IP warmup,
                        reputation management.
                    </p>
                    <p>
                        We didn't want to run a mail server. We wanted SES with
                        a real product around it. So we built Larasend, ran it
                        in production for our own transactional email, and now
                        it's yours.
                    </p>
                </div>
            </section>

            <section id="setup" class="section container">
                <div class="section-head reveal">
                    <span>Install to first send</span>
                    <h2>
                        Production setup without a weekend in the AWS console.
                    </h2>
                    <p>
                        Larasend stays self-hosted first. Bring your database,
                        your AWS account, and your sending domain.
                    </p>
                </div>

                <div class="steps reveal">
                    <article
                        v-for="(step, index) in setupSteps"
                        :key="step[0]"
                        class="step"
                        :style="{ '--card-delay': `${index * 90}ms` }"
                    >
                        <code>{{ step[0] }}</code>
                        <h3>{{ step[1] }}</h3>
                        <p>{{ step[2] }}</p>
                    </article>
                </div>
            </section>

            <section class="section reveal container">
                <div class="compare">
                    <div class="compare-title">
                        <span>Why Larasend</span>
                        <h2>
                            The dashboard of a hosted API. The ownership of raw
                            SES.
                        </h2>
                    </div>
                    <div class="compare-table">
                        <div>
                            <b>Capability</b><b>Raw SES</b
                            ><b>Hosted email APIs</b><b>Larasend</b>
                        </div>
                        <div>
                            <span>Dashboard, previews, logs</span><em>None</em
                            ><em>Included</em><strong>Included</strong>
                        </div>
                        <div>
                            <span>Laravel mail transport</span><em>Build it</em
                            ><em>SDK migration</em
                            ><strong>One line of .env</strong>
                        </div>
                        <div>
                            <span>Webhook retries and logs</span
                            ><em>Build it</em><em>Included</em
                            ><strong>Included</strong>
                        </div>
                        <div>
                            <span>Data stays on your infra</span><em>Yes</em
                            ><em>No</em><strong>Yes</strong>
                        </div>
                        <div>
                            <span>Cost at 1M emails/mo</span><em>~$100</em
                            ><em>$$$$</em><strong>~$100</strong>
                        </div>
                        <div>
                            <span>Open source</span><em>No</em><em>No</em
                            ><strong>MIT</strong>
                        </div>
                    </div>
                </div>
            </section>

            <section id="open-source" class="section oss reveal container">
                <div>
                    <span class="section-kicker">Open source</span>
                    <h2>
                        Host it yourself. Fork it. Ship email with fewer black
                        boxes.
                    </h2>
                    <p>
                        Larasend is designed for teams that want a polished mail
                        API and dashboard running on their own SES account,
                        their own storage, and a codebase they can inspect.
                    </p>
                    <div class="cta-row">
                        <a
                            class="btn primary big"
                            href="https://github.com/savvyagents/larasend"
                            target="_blank"
                            rel="noopener"
                        >
                            Open GitHub
                        </a>
                        <Link
                            v-if="primaryCta.kind === 'inertia'"
                            class="btn big"
                            :href="primaryCta.href"
                            >{{ primaryCta.label }}</Link
                        >
                        <a
                            v-else
                            class="btn big"
                            :href="primaryCtaAnchorHref"
                            >{{ primaryCta.label }}</a
                        >
                    </div>
                </div>
                <div class="repo-card">
                    <div>
                        <span class="mark"
                            ><AppLogoIcon class="brand-mark-icon"
                        /></span>
                        <b>savvyagents / larasend</b>
                    </div>
                    <p>
                        Open-source SES dashboard, sending API, webhook router,
                        and Laravel mailer package.
                    </p>
                    <ul>
                        <li><span></span> Vue + Inertia dashboard</li>
                        <li><span></span> Amazon SES v2 sending</li>
                        <li><span></span> Docker Compose install</li>
                        <li><span></span> Laravel package integration</li>
                    </ul>
                </div>
            </section>

            <section id="cta" class="final-cta reveal container">
                <h2>From zero to sending in one command.</h2>
                <p>
                    Pull the compose file, add your SES credentials, and start
                    sending transactional email with the observability your team
                    needs.
                </p>
                <div class="install-cmd">
                    <code>{{ installCommand }}</code>
                    <button
                        type="button"
                        class="btn ghost"
                        @click="copyInstallCommand"
                    >
                        {{ installCopied ? 'Copied' : 'Copy' }}
                    </button>
                </div>
                <div class="cta-row">
                    <Link
                        v-if="primaryCta.kind === 'inertia'"
                        class="btn primary big"
                        :href="primaryCta.href"
                    >
                        {{ primaryCta.label }}
                        <span aria-hidden="true">→</span>
                    </Link>
                    <a
                        v-else
                        class="btn primary big"
                        :href="primaryCtaAnchorHref"
                    >
                        {{ primaryCta.label }}
                        <span aria-hidden="true">→</span>
                    </a>
                    <a
                        class="btn big"
                        :href="repoUrl"
                        target="_blank"
                        rel="noopener"
                    >
                        Star on GitHub
                    </a>
                </div>
            </section>
        </main>

        <footer>
            <div class="footer-inner">
                <div>
                    <div class="brand footer-brand">
                        <span class="mark"
                            ><AppLogoIcon class="brand-mark-icon" /></span
                        ><span>larasend</span>
                    </div>
                    <p>
                        Open-source email infrastructure for Laravel teams
                        running on Amazon SES.
                    </p>
                </div>
                <a href="#features">Features</a>
                <a href="#setup">Setup</a>
                <a href="#code">Laravel</a>
                <a
                    href="https://github.com/savvyagents/larasend"
                    target="_blank"
                    rel="noopener"
                    >GitHub</a
                >
            </div>
            <div class="legal">
                <span
                    >© 2026 Larasend. Built by
                    <a
                        href="https://savvyagents.ai"
                        target="_blank"
                        rel="noopener"
                        >Savvy Agents</a
                    >.</span
                >
                <span>Docker-ready · Laravel-native · SES-backed</span>
            </div>
        </footer>
    </div>
</template>

<style scoped>
.landing {
    --bg: #06070a;
    --bg-2: #0b0d11;
    --panel: #0f1216;
    --panel-2: #15181d;
    --line: #1c1f24;
    --line-strong: #2a3037;
    --fg: #eef0f3;
    --fg-2: #a5acb5;
    --fg-3: #6a7079;
    --accent: #54e0c0;
    --blue: #7fb4ff;
    --violet: #b08af3;
    --amber: #f0b85c;
    --rose: #f06f6f;
    --green: #5cd494;
    min-height: 100vh;
    overflow-x: hidden;
    background: var(--bg);
    color: var(--fg);
    font-family: 'Geist', ui-sans-serif, system-ui, sans-serif;
    letter-spacing: -0.005em;
    isolation: isolate;
}

.landing * {
    box-sizing: border-box;
}

.landing a {
    color: inherit;
    text-decoration: none;
}

.bg-field {
    position: fixed;
    inset: 0;
    z-index: 0;
    overflow: hidden;
    pointer-events: none;
}

.bg-field .grid {
    position: absolute;
    inset: -180px;
    background-image:
        linear-gradient(rgba(255, 255, 255, 0.025) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255, 255, 255, 0.025) 1px, transparent 1px);
    background-size: 48px 48px;
    animation: grid-drift 18s linear infinite;
    mask-image: radial-gradient(
        ellipse 70% 58% at 50% 28%,
        #000 30%,
        transparent 80%
    );
}

.grain,
.spotlight {
    position: absolute;
    inset: 0;
}

.grain {
    opacity: 0.24;
    background-image: radial-gradient(
        circle at 25% 18%,
        rgba(255, 255, 255, 0.18) 0 1px,
        transparent 1px
    );
    background-size: 4px 4px;
    mix-blend-mode: overlay;
}

.spotlight {
    background:
        radial-gradient(
            circle at var(--pointer-x, 52%) var(--pointer-y, 18%),
            rgba(84, 224, 192, 0.2),
            transparent 28rem
        ),
        linear-gradient(180deg, transparent 0%, rgba(6, 7, 10, 0.7) 80%);
    transition: background 0.2s ease;
}

.glow {
    position: absolute;
    border-radius: 999px;
    filter: blur(82px);
    opacity: 0.85;
    animation: glow-breathe 10s ease-in-out infinite alternate;
}

.glow-one {
    top: -190px;
    left: -150px;
    width: 720px;
    height: 520px;
    background: rgba(84, 224, 192, 0.5);
}

.glow-two {
    top: 120px;
    right: -220px;
    width: 640px;
    height: 480px;
    background: rgba(127, 180, 255, 0.38);
    animation-delay: -3s;
}

.glow-three {
    top: 58%;
    left: 28%;
    width: 520px;
    height: 420px;
    background: rgba(176, 138, 243, 0.28);
    animation-delay: -6s;
}

.orbital {
    position: absolute;
    width: 34rem;
    height: 34rem;
    border: 1px solid rgba(84, 224, 192, 0.1);
    border-radius: 999px;
    opacity: 0.7;
    transform-style: preserve-3d;
}

.orbital::before,
.orbital::after {
    position: absolute;
    width: 8px;
    height: 8px;
    border-radius: 999px;
    background: var(--accent);
    box-shadow: 0 0 24px rgba(84, 224, 192, 0.8);
    content: '';
}

.orbital::before {
    top: 20%;
    right: 8%;
}

.orbital::after {
    bottom: 16%;
    left: 11%;
    background: var(--blue);
    box-shadow: 0 0 24px rgba(127, 180, 255, 0.8);
}

.orbital-one {
    top: 16rem;
    right: -18rem;
    animation: slow-orbit 28s linear infinite;
}

.orbital-two {
    top: 48rem;
    left: -22rem;
    width: 28rem;
    height: 28rem;
    animation: slow-orbit 34s linear infinite reverse;
}

.container,
.nav-inner,
.footer-inner,
.legal {
    position: relative;
    z-index: 1;
    width: min(1200px, calc(100vw - 48px));
    margin: 0 auto;
}

.nav {
    position: sticky;
    top: 0;
    z-index: 20;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    background: rgba(6, 7, 10, 0.72);
    backdrop-filter: blur(18px);
    animation: drop-in 0.7s cubic-bezier(0.16, 1, 0.3, 1) both;
}

.nav-inner {
    display: flex;
    align-items: center;
    gap: 32px;
    height: 60px;
}

.brand {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    font-size: 15px;
    font-weight: 600;
}

.mark {
    display: grid;
    width: 24px;
    height: 24px;
    place-items: center;
    border-radius: 7px;
    background: var(--accent);
    color: #062019;
    box-shadow: 0 0 28px rgba(84, 224, 192, 0.18);
}

.brand-mark-icon {
    width: 18px;
    height: 18px;
}

.nav-links {
    display: flex;
    gap: 24px;
    color: var(--fg-2);
    font-size: 13.5px;
}

.nav-links a,
footer a {
    position: relative;
    transition: color 0.15s ease;
}

.nav-links a::after {
    position: absolute;
    right: 0;
    bottom: -8px;
    left: 0;
    height: 1px;
    background: linear-gradient(90deg, transparent, var(--accent), transparent);
    opacity: 0;
    transform: scaleX(0.35);
    transition:
        opacity 0.18s ease,
        transform 0.18s ease;
    content: '';
}

.nav-links a:hover,
footer a:hover {
    color: var(--fg);
}

.nav-links a:hover::after {
    opacity: 1;
    transform: scaleX(1);
}

.nav-cta {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-left: auto;
}

.btn,
.github-pill {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    height: 36px;
    padding: 0 14px;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: var(--panel);
    color: var(--fg);
    font-size: 13.5px;
    font-weight: 500;
    line-height: 1;
    transition:
        transform 0.15s ease,
        background 0.15s ease,
        border-color 0.15s ease,
        filter 0.15s ease;
    overflow: hidden;
}

.btn::before,
.github-pill::before {
    position: absolute;
    inset: 0;
    background: linear-gradient(
        120deg,
        transparent,
        rgba(255, 255, 255, 0.16),
        transparent
    );
    opacity: 0;
    transform: translateX(-110%);
    transition:
        opacity 0.2s ease,
        transform 0.45s ease;
    content: '';
}

.btn > *,
.github-pill > * {
    position: relative;
    z-index: 1;
}

.btn:hover,
.github-pill:hover {
    transform: translateY(-2px);
    border-color: var(--line-strong);
    background: var(--panel-2);
}

.btn:hover::before,
.github-pill:hover::before {
    opacity: 1;
    transform: translateX(110%);
}

.btn.primary {
    border-color: var(--accent);
    background: var(--accent);
    color: #062019;
    font-weight: 700;
    box-shadow:
        0 12px 36px rgba(84, 224, 192, 0.18),
        inset 0 -10px 20px rgba(6, 32, 25, 0.1);
}

.btn.primary:hover {
    filter: brightness(1.08);
}

.btn.ghost {
    border-color: transparent;
    background: transparent;
    color: var(--fg-2);
}

.btn.ghost:hover {
    background: rgba(255, 255, 255, 0.04);
    color: var(--fg);
}

.btn.big {
    height: 44px;
    padding: 0 18px;
    border-radius: 9px;
    font-size: 14px;
}

.github-pill {
    font-family: 'Geist Mono', ui-monospace, monospace;
    font-size: 12px;
    color: var(--fg-2);
}

.hero {
    padding: 84px 0 60px;
    text-align: center;
}

.hero .cta-row {
    justify-content: center;
}

.eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    max-width: 100%;
    padding: 5px 12px 5px 6px;
    border: 1px solid var(--line);
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.025);
    color: var(--fg-2);
    font:
        500 12px 'Geist Mono',
        ui-monospace,
        monospace;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.02);
}

.pill {
    padding: 2px 7px;
    border-radius: 999px;
    background: var(--accent);
    color: #062019;
    font-size: 10.5px;
    font-weight: 700;
    text-transform: uppercase;
}

.pulse-dot {
    width: 6px;
    height: 6px;
    border-radius: 999px;
    background: var(--green);
    box-shadow: 0 0 0 4px rgba(92, 212, 148, 0.18);
    animation: pulse-ring 1.8s ease-out infinite;
}

.hero h1 {
    max-width: 1000px;
    margin: 24px auto;
    font-size: clamp(52px, 8vw, 92px);
    font-weight: 650;
    line-height: 0.98;
    letter-spacing: -0.06em;
}

.hero-title .line {
    display: block;
    overflow: hidden;
}

.hero h1 .line {
    color: var(--fg);
}

.hero h1 .accent-line {
    display: block;
    color: var(--accent);
    font-family: 'Instrument Serif', Georgia, serif;
    font-style: italic;
    font-weight: 400;
    letter-spacing: -0.02em;
}

.hero-title .line {
    animation: text-rise 0.9s cubic-bezier(0.16, 1, 0.3, 1) both;
}

.hero-title .line:nth-child(2) {
    animation-delay: 0.08s;
}

.hero-title .line:nth-child(3) {
    animation-delay: 0.16s;
}

.lede {
    max-width: 660px;
    margin: 0 auto 36px;
    color: var(--fg-2);
    font-size: 18px;
    line-height: 1.58;
}

.cta-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.hero-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1px;
    max-width: 920px;
    margin: 54px auto 0;
    overflow: hidden;
    border: 1px solid var(--line);
    border-radius: 14px;
    background: var(--line);
    box-shadow: 0 22px 80px rgba(0, 0, 0, 0.28);
}

.hero-stats div {
    position: relative;
    padding: 18px;
    background: rgba(15, 18, 22, 0.88);
    animation: stat-pop 0.7s cubic-bezier(0.16, 1, 0.3, 1) both;
    animation-delay: var(--stagger);
    overflow: hidden;
}

.hero-stats div::before {
    position: absolute;
    inset: 0;
    background: radial-gradient(
        circle at 20% 0%,
        rgba(84, 224, 192, 0.14),
        transparent 45%
    );
    opacity: 0;
    transition: opacity 0.2s ease;
    content: '';
}

.hero-stats div:hover::before {
    opacity: 1;
}

.hero-stats strong {
    display: block;
    margin-bottom: 5px;
    font-size: 20px;
    letter-spacing: -0.025em;
}

.hero-stats span {
    color: var(--fg-3);
    font:
        500 11px 'Geist Mono',
        ui-monospace,
        monospace;
    text-transform: uppercase;
    letter-spacing: 0.07em;
}

.hero-visual {
    margin-top: 70px;
    perspective: 1600px;
}

.code-card,
.feature-card,
.step,
.repo-card,
.compare,
.story-card {
    border: 1px solid var(--line);
    border-radius: 14px;
    background: rgba(15, 18, 22, 0.88);
}

.marquee {
    overflow: hidden;
    padding: 36px 0 4px;
    color: var(--fg-3);
    font:
        500 12px 'Geist Mono',
        ui-monospace,
        monospace;
    mask-image: linear-gradient(
        90deg,
        transparent,
        #000 12%,
        #000 88%,
        transparent
    );
}

.marquee-track {
    display: flex;
    width: max-content;
    gap: 12px;
    animation: marquee-slide 32s linear infinite;
}

.marquee span {
    padding: 6px 11px;
    border: 1px solid var(--line);
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.02);
}

.section {
    padding-top: 116px;
}

.reveal {
    opacity: 0;
    transform: translateY(26px);
    transition:
        opacity 0.8s cubic-bezier(0.16, 1, 0.3, 1),
        transform 0.8s cubic-bezier(0.16, 1, 0.3, 1);
}

.reveal.is-visible,
.hero .reveal {
    opacity: 1;
    transform: translateY(0);
}

.hero .reveal:nth-child(1) {
    transition-delay: 40ms;
}

.hero .reveal:nth-child(2) {
    transition-delay: 120ms;
}

.hero .reveal:nth-child(3) {
    transition-delay: 200ms;
}

.hero .reveal:nth-child(4) {
    transition-delay: 280ms;
}

.section-head {
    max-width: 760px;
    margin: 0 auto 48px;
    text-align: center;
}

.section-head > span,
.section-kicker {
    display: inline-flex;
    margin-bottom: 14px;
    color: var(--accent);
    font:
        600 11.5px 'Geist Mono',
        ui-monospace,
        monospace;
    text-transform: uppercase;
    letter-spacing: 0.1em;
}

.section h2,
.section-head h2,
.compare-title h2,
.final-cta h2 {
    margin: 0;
    font-size: clamp(34px, 5vw, 52px);
    font-weight: 650;
    line-height: 1.04;
    letter-spacing: -0.04em;
}

.section p,
.section-head p,
.final-cta p,
.oss p {
    color: var(--fg-2);
    font-size: 17px;
    line-height: 1.62;
}

.features-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
}

.feature-card {
    position: relative;
    min-height: 300px;
    padding: 24px;
    opacity: 0;
    transform: translateY(18px);
    transition:
        opacity 0.7s cubic-bezier(0.16, 1, 0.3, 1),
        transform 0.7s cubic-bezier(0.16, 1, 0.3, 1),
        border-color 0.18s ease,
        box-shadow 0.18s ease;
    transition-delay: var(--card-delay);
    overflow: hidden;
}

.features-grid.is-visible .feature-card,
.steps.is-visible .step {
    opacity: 1;
    transform: translateY(0);
}

.feature-card::before,
.step::before,
.repo-card::before,
.code-card::before,
.compare::before {
    position: absolute;
    inset: -1px;
    background: radial-gradient(
        circle at var(--pointer-x, 50%) 0%,
        rgba(84, 224, 192, 0.14),
        transparent 42%
    );
    opacity: 0;
    transition: opacity 0.2s ease;
    content: '';
}

.feature-card:hover {
    transform: translateY(-6px);
    border-color: var(--line-strong);
    box-shadow: 0 22px 70px rgba(0, 0, 0, 0.28);
}

.feature-card:hover::before,
.step:hover::before,
.repo-card:hover::before,
.code-card:hover::before,
.compare:hover::before {
    opacity: 1;
}

.feature-icon {
    position: relative;
    width: 31px;
    height: 31px;
    margin-bottom: 16px;
    border-radius: 9px;
    background: rgba(84, 224, 192, 0.16);
}

.feature-icon::after {
    position: absolute;
    inset: 8px;
    border-radius: inherit;
    background: var(--accent);
    opacity: 0.7;
    filter: blur(4px);
    animation: icon-glow 2.5s ease-in-out infinite alternate;
    content: '';
}

.feature-card.blue .feature-icon {
    background: rgba(127, 180, 255, 0.18);
}

.feature-card.violet .feature-icon {
    background: rgba(176, 138, 243, 0.18);
}

.feature-card.amber .feature-icon {
    background: rgba(240, 184, 92, 0.18);
}

.feature-card h3,
.step h3 {
    position: relative;
    margin: 0 0 8px;
    font-size: 18px;
    line-height: 1.2;
    letter-spacing: -0.02em;
}

.feature-card p,
.step p,
.repo-card p {
    position: relative;
    margin: 0;
    color: var(--fg-2);
    font-size: 13.5px;
    line-height: 1.6;
}

.two-col,
.oss {
    display: grid;
    grid-template-columns: minmax(0, 0.92fr) minmax(0, 1fr);
    gap: 44px;
    align-items: center;
}

.copy-panel {
    transform: translateY(0);
}

.two-col ul {
    display: grid;
    gap: 10px;
    margin: 24px 0 0;
    padding: 0;
    color: var(--fg-2);
    list-style: none;
}

.two-col li::before {
    margin-right: 9px;
    color: var(--accent);
    content: '✓';
}

.code-card {
    position: relative;
    overflow: hidden;
    box-shadow: 0 24px 90px rgba(0, 0, 0, 0.35);
}

.code-card::after {
    position: absolute;
    top: 42px;
    right: 0;
    left: 0;
    height: 1px;
    background: linear-gradient(
        90deg,
        transparent,
        rgba(84, 224, 192, 0.55),
        transparent
    );
    animation: scan-line 3.2s ease-in-out infinite;
    content: '';
}

.code-tabs {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 42px;
    padding: 0 14px;
    border-bottom: 1px solid var(--line);
    background: rgba(0, 0, 0, 0.18);
}

.code-tabs span:first-child {
    width: 8px;
    height: 8px;
    border-radius: 999px;
    background: var(--accent);
}

.code-tabs span:last-child {
    color: var(--fg-2);
    font:
        600 11px 'Geist Mono',
        ui-monospace,
        monospace;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

pre {
    position: relative;
    margin: 0;
    padding: 22px;
    overflow-x: auto;
    color: #d8dee9;
    font:
        500 13px/1.7 'Geist Mono',
        ui-monospace,
        monospace;
}

.code-cursor {
    display: inline-block;
    width: 7px;
    height: 1.1em;
    margin-right: 8px;
    border-radius: 2px;
    background: var(--accent);
    vertical-align: -0.2em;
    animation: cursor-blink 1s steps(2, jump-none) infinite;
}

.steps {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
}

.step {
    position: relative;
    min-height: 240px;
    padding: 22px;
    opacity: 0;
    transform: translateY(18px);
    transition:
        opacity 0.7s cubic-bezier(0.16, 1, 0.3, 1),
        transform 0.7s cubic-bezier(0.16, 1, 0.3, 1),
        border-color 0.18s ease,
        box-shadow 0.18s ease;
    transition-delay: var(--card-delay);
    overflow: hidden;
}

.step:hover {
    border-color: var(--line-strong);
    box-shadow: 0 22px 70px rgba(0, 0, 0, 0.22);
    transform: translateY(-5px);
}

.step code {
    display: block;
    margin-bottom: 34px;
    color: var(--accent);
    font:
        600 12px 'Geist Mono',
        ui-monospace,
        monospace;
}

.compare {
    position: relative;
    overflow: hidden;
    box-shadow: 0 24px 90px rgba(0, 0, 0, 0.22);
}

.compare-title {
    padding: 28px;
    border-bottom: 1px solid var(--line);
}

.compare-title span {
    display: block;
    margin-bottom: 10px;
    color: var(--accent);
    font:
        600 11px 'Geist Mono',
        ui-monospace,
        monospace;
    text-transform: uppercase;
    letter-spacing: 0.1em;
}

.compare-table > div {
    display: grid;
    grid-template-columns: 1.3fr 0.6fr 0.8fr 0.8fr;
    gap: 1px;
    border-bottom: 1px solid var(--line);
    transition:
        background 0.18s ease,
        transform 0.18s ease;
}

.compare-table > div:not(:first-child):hover {
    background: rgba(84, 224, 192, 0.035);
    transform: translateX(4px);
}

.compare-table > div:last-child {
    border-bottom: 0;
}

.compare-table span,
.compare-table em,
.compare-table strong,
.compare-table b {
    padding: 15px 18px;
    color: var(--fg-2);
    font-style: normal;
    font-weight: 500;
}

.compare-table b {
    color: var(--fg);
    font:
        600 11px 'Geist Mono',
        ui-monospace,
        monospace;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.compare-table strong {
    color: var(--accent);
}

.story-card {
    max-width: 780px;
    margin: 0 auto;
    padding: 44px 48px;
    box-shadow: 0 24px 90px rgba(0, 0, 0, 0.22);
}

.story-card .section-kicker {
    display: block;
    margin-bottom: 12px;
    color: var(--accent);
    font:
        600 11px 'Geist Mono',
        ui-monospace,
        monospace;
    text-transform: uppercase;
    letter-spacing: 0.1em;
}

.story-card h2 {
    margin: 0 0 18px;
    font-size: 30px;
    font-weight: 640;
    letter-spacing: -0.02em;
}

.story-card p {
    margin: 0 0 14px;
    color: var(--fg-2);
    font-size: 16px;
    line-height: 1.75;
}

.story-card p a {
    color: var(--accent);
    text-decoration: underline;
    text-decoration-color: rgba(84, 224, 192, 0.4);
    text-underline-offset: 2px;
}

.story-card p a:hover {
    text-decoration-color: var(--accent);
}

.story-card p:last-child {
    margin-bottom: 0;
}

.copy-panel p code {
    padding: 2px 7px;
    border: 1px solid var(--line);
    border-radius: 6px;
    background: rgba(0, 0, 0, 0.3);
    color: var(--accent);
    font:
        500 13px 'Geist Mono',
        ui-monospace,
        monospace;
}

.install-cmd {
    display: flex;
    align-items: center;
    gap: 12px;
    max-width: 760px;
    margin: 26px auto 30px;
    padding: 10px 10px 10px 18px;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: rgba(0, 0, 0, 0.35);
}

.install-cmd code {
    flex: 1;
    overflow-x: auto;
    color: #d8dee9;
    font:
        500 12.5px/1.6 'Geist Mono',
        ui-monospace,
        monospace;
    text-align: left;
    white-space: nowrap;
}

.install-cmd .btn {
    flex-shrink: 0;
}

.oss {
    padding-bottom: 40px;
}

.repo-card {
    position: relative;
    padding: 24px;
    overflow: hidden;
    box-shadow: 0 24px 90px rgba(0, 0, 0, 0.28);
}

.repo-card > div {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 18px;
}

.repo-card ul {
    display: grid;
    gap: 12px;
    margin: 22px 0 0;
    padding: 0;
    color: var(--fg-2);
    list-style: none;
    font:
        500 13px 'Geist Mono',
        ui-monospace,
        monospace;
}

.repo-card li span {
    display: inline-block;
    width: 8px;
    height: 8px;
    margin-right: 9px;
    border-radius: 999px;
    background: var(--accent);
    animation: pulse-ring 2.2s ease-out infinite;
}

.final-cta {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    margin-top: 90px;
    padding: 72px 24px;
    border: 1px solid var(--line);
    border-radius: 18px;
    background:
        radial-gradient(
            circle at 50% 0%,
            rgba(84, 224, 192, 0.18),
            transparent 42%
        ),
        rgba(15, 18, 22, 0.88);
    text-align: center;
    overflow: hidden;
}

.final-cta::before {
    position: absolute;
    top: -50%;
    left: 50%;
    width: 52rem;
    height: 52rem;
    border: 1px solid rgba(84, 224, 192, 0.12);
    border-radius: 999px;
    transform: translateX(-50%);
    animation: slow-orbit 32s linear infinite;
    content: '';
}

.final-cta > * {
    position: relative;
    z-index: 1;
}

.final-cta h2 {
    max-width: 760px;
}

.final-cta p {
    max-width: 620px;
}

footer {
    position: relative;
    z-index: 1;
    margin-top: 80px;
    border-top: 1px solid var(--line);
    background: var(--bg);
}

.footer-inner {
    display: grid;
    grid-template-columns: 1.5fr repeat(4, auto);
    gap: 28px;
    align-items: start;
    padding: 34px 0 24px;
}

.footer-inner p {
    max-width: 340px;
    margin: 12px 0 0;
    color: var(--fg-2);
    font-size: 13px;
}

.footer-inner > a {
    color: var(--fg-2);
    font-size: 13px;
}

.legal {
    display: flex;
    justify-content: space-between;
    gap: 20px;
    padding: 18px 0 28px;
    border-top: 1px solid var(--line);
    color: var(--fg-3);
    font:
        500 11px 'Geist Mono',
        ui-monospace,
        monospace;
}

.legal a {
    color: var(--fg-2);
}

.legal a:hover {
    color: var(--accent);
}

@keyframes drop-in {
    from {
        opacity: 0;
        transform: translateY(-14px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes grid-drift {
    from {
        transform: translate3d(0, 0, 0);
    }

    to {
        transform: translate3d(48px, 48px, 0);
    }
}

@keyframes glow-breathe {
    from {
        transform: scale(0.94) translate3d(0, 0, 0);
    }

    to {
        transform: scale(1.08) translate3d(26px, -18px, 0);
    }
}

@keyframes slow-orbit {
    from {
        transform: rotate(0deg);
    }

    to {
        transform: rotate(360deg);
    }
}

@keyframes pulse-ring {
    0% {
        box-shadow: 0 0 0 0 rgba(84, 224, 192, 0.28);
    }

    70% {
        box-shadow: 0 0 0 9px rgba(84, 224, 192, 0);
    }

    100% {
        box-shadow: 0 0 0 0 rgba(84, 224, 192, 0);
    }
}

@keyframes text-rise {
    from {
        opacity: 0;
        transform: translateY(110%) rotate(1deg);
    }

    to {
        opacity: 1;
        transform: translateY(0) rotate(0deg);
    }
}

@keyframes stat-pop {
    from {
        opacity: 0;
        transform: translateY(14px) scale(0.98);
    }

    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

@keyframes marquee-slide {
    from {
        transform: translateX(0);
    }

    to {
        transform: translateX(-50%);
    }
}

@keyframes icon-glow {
    from {
        opacity: 0.35;
        transform: scale(0.86);
    }

    to {
        opacity: 0.85;
        transform: scale(1.08);
    }
}

@keyframes scan-line {
    0%,
    100% {
        opacity: 0;
        transform: translateY(0);
    }

    40%,
    60% {
        opacity: 1;
    }

    50% {
        transform: translateY(190px);
    }
}

@keyframes cursor-blink {
    50% {
        opacity: 0;
    }
}

@media (max-width: 980px) {
    .nav-links,
    .github-pill {
        display: none;
    }

    .hero-stats,
    .features-grid,
    .steps,
    .two-col,
    .oss {
        grid-template-columns: 1fr;
    }

    .footer-inner {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 640px) {
    .container,
    .nav-inner,
    .footer-inner,
    .legal {
        width: min(100vw - 32px, 1200px);
    }

    .nav-inner {
        gap: 12px;
    }

    .nav-cta .ghost {
        display: none;
    }

    .hero {
        padding-top: 56px;
    }

    .eyebrow {
        align-items: flex-start;
        border-radius: 14px;
        white-space: normal;
    }

    .hero h1 {
        font-size: 48px;
    }

    .compare-table > div {
        grid-template-columns: 1fr;
    }

    .story-card {
        padding: 28px 22px;
    }

    .install-cmd {
        flex-direction: column;
        align-items: stretch;
    }

    .install-cmd code {
        white-space: normal;
        word-break: break-all;
    }

    .footer-inner,
    .legal {
        grid-template-columns: 1fr;
    }
}

@media (prefers-reduced-motion: reduce) {
    .landing *,
    .landing *::before,
    .landing *::after {
        animation-duration: 0.001ms !important;
        animation-iteration-count: 1 !important;
        scroll-behavior: auto !important;
        transition-duration: 0.001ms !important;
    }

    .reveal,
    .feature-card,
    .step {
        opacity: 1;
        transform: none;
    }
}
</style>
