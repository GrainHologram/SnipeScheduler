'use strict';

const { SlashCommandBuilder, EmbedBuilder } = require('discord.js');
const pool = require('../db');

const ADMIN_USER_ID = process.env.ADMIN_USER_ID || '';

const STATUS_EMOJI = {
  open: '\uD83D\uDD35',       // 🔵
  approved: '\u2705',         // ✅
  held: '\u23F8\uFE0F',       // ⏸️
};

function statusLabel(status) {
  const emoji = STATUS_EMOJI[status] ?? '•';
  const label = status.charAt(0).toUpperCase() + status.slice(1);
  return `${emoji} ${label}`;
}

const data = new SlashCommandBuilder()
  .setName('list-requests')
  .setDescription('List all active purchase requests (admin only)');

async function execute(interaction) {
  // Gate to admin user only
  if (interaction.user.id !== ADMIN_USER_ID) {
    await interaction.reply({
      content: 'This command is restricted.',
      flags: 64, // ephemeral
    });
    return;
  }

  await interaction.deferReply(); // public reply

  let rows;
  try {
    [rows] = await pool.execute(
      `SELECT id, item_name, submitter_name, department, quantity,
              status, importance, estimated_cost, is_faculty, created_at, item_url
       FROM purchase_requests
       WHERE status IN ('open', 'approved', 'held')
       ORDER BY
         FIELD(status, 'open', 'approved', 'held'),
         FIELD(importance, 'critical', 'high', 'medium', 'low'),
         created_at ASC
       LIMIT 25`
    );
  } catch (err) {
    console.error('DB error fetching requests:', err);
    await interaction.editReply({ content: 'Database error fetching requests.' });
    return;
  }

  if (rows.length === 0) {
    await interaction.editReply({ content: 'No active purchase requests.' });
    return;
  }

  // Build a table-style message using a code block for alignment
  const lines = [];
  lines.push('```');
  lines.push(
    padRight('#', 4) +
    padRight('Status', 10) +
    padRight('Item', 28) +
    padRight('Dept', 16) +
    padRight('Qty', 5) +
    padRight('Cost', 10) +
    padRight('Ext', 12) +
    'Submitter'
  );
  lines.push('-'.repeat(102));

  for (const row of rows) {
    const cost = row.estimated_cost !== null
      ? '$' + Number(row.estimated_cost).toFixed(2)
      : '—';
    const ext = row.estimated_cost !== null
      ? '$' + (Number(row.estimated_cost) * row.quantity).toFixed(2)
      : '—';
    const faculty = row.is_faculty ? ' [F]' : '';

    lines.push(
      padRight(String(row.id), 4) +
      padRight(row.status, 10) +
      padRight(truncate(row.item_name, 26), 28) +
      padRight(truncate(row.department, 14), 16) +
      padRight(String(row.quantity), 5) +
      padRight(cost, 10) +
      padRight(ext, 12) +
      truncate(row.submitter_name, 20) + faculty
    );
  }

  lines.push('```');

  const totalCost = rows.reduce((sum, r) => {
    return sum + (r.estimated_cost !== null ? Number(r.estimated_cost) * r.quantity : 0);
  }, 0);

  const summary = `**${rows.length}** active request${rows.length !== 1 ? 's' : ''}`;
  const costSummary = totalCost > 0
    ? ` · Est. total: **$${totalCost.toFixed(2)}**`
    : '';

  // Build links embed for rows with URLs
  const embeds = [];
  const linksRows = rows.filter((r) => r.item_url);
  if (linksRows.length > 0) {
    const linkLines = linksRows.map(
      (r) => `**#${r.id}** [${r.item_name}](${r.item_url})`
    );
    const linksEmbed = new EmbedBuilder()
      .setColor(0x6366f1)
      .setTitle('Item Links')
      .setDescription(linkLines.join('\n'));
    embeds.push(linksEmbed);
  }

  await interaction.editReply({
    content: summary + costSummary + '\n' + lines.join('\n'),
    embeds,
  });
}

function padRight(str, len) {
  if (str.length >= len) return str.slice(0, len);
  return str + ' '.repeat(len - str.length);
}

function truncate(str, max) {
  if (str.length <= max) return str;
  return str.slice(0, max - 1) + '…';
}

module.exports = { data, execute };
