'use strict';

const { SlashCommandBuilder, EmbedBuilder } = require('discord.js');
const pool = require('../db');

// ---------------------------------------------------------------------------
// Status display helpers
// ---------------------------------------------------------------------------

const STATUS_EMOJI = {
  open: '\uD83D\uDD35',       // 🔵
  approved: '\u2705',         // ✅
  held: '\u23F8\uFE0F',       // ⏸️
  purchased: '\uD83D\uDED2',  // 🛒
  denied: '\u274C',           // ❌
  duplicate: '\uD83D\uDD01',  // 🔁
};

function statusLabel(status) {
  const emoji = STATUS_EMOJI[status] ?? '•';
  const label = status.charAt(0).toUpperCase() + status.slice(1);
  return `${emoji} ${label}`;
}

function formatDate(date) {
  if (!date) return 'Unknown';
  const d = new Date(date);
  return d.toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    timeZone: 'UTC',
  });
}

// ---------------------------------------------------------------------------
// Slash command definition
// ---------------------------------------------------------------------------

const data = new SlashCommandBuilder()
  .setName('my-requests')
  .setDescription('View your purchase requests');

// ---------------------------------------------------------------------------
// Slash command handler
// ---------------------------------------------------------------------------

async function execute(interaction) {
  await interaction.deferReply({ flags: 64 }); // ephemeral

  let rows;
  try {
    [rows] = await pool.execute(
      `SELECT id, item_name, status, department, quantity, created_at, item_url
       FROM purchase_requests
       WHERE discord_user_id = ?
       ORDER BY created_at DESC
       LIMIT 10`,
      [interaction.user.id]
    );
  } catch (err) {
    console.error('DB error fetching purchase requests:', err);
    await interaction.editReply({
      content: 'Sorry, there was a database error fetching your requests. Please try again later.',
    });
    return;
  }

  if (rows.length === 0) {
    await interaction.editReply({
      content: "You haven't submitted any purchase requests yet.",
    });
    return;
  }

  const embed = new EmbedBuilder()
    .setColor(0x5865f2) // Discord blurple
    .setTitle('Your Purchase Requests')
    .setDescription(`Showing your ${rows.length === 10 ? 'most recent 10' : rows.length} request${rows.length === 1 ? '' : 's'}.`)
    .setTimestamp();

  for (const row of rows) {
    const lines = [
      `**Status:** ${statusLabel(row.status)}`,
      `**Department:** ${row.department}`,
      `**Quantity:** ${row.quantity}`,
      `**Submitted:** ${formatDate(row.created_at)}`,
    ];
    if (row.item_url) {
      lines.push(`**Link:** [View item](${row.item_url})`);
    }

    embed.addFields({
      name: `#${row.id} — ${row.item_name}`,
      value: lines.join('\n'),
      inline: false,
    });
  }

  await interaction.editReply({ embeds: [embed] });
}

// ---------------------------------------------------------------------------

module.exports = { data, execute };
