'use strict';

const {
  SlashCommandBuilder,
  ModalBuilder,
  TextInputBuilder,
  TextInputStyle,
  ActionRowBuilder,
  EmbedBuilder,
} = require('discord.js');

const pool = require('../db');

// ---------------------------------------------------------------------------
// Slash command definition
// ---------------------------------------------------------------------------

const data = new SlashCommandBuilder()
  .setName('purchase-request')
  .setDescription('Submit an equipment purchase request');

// ---------------------------------------------------------------------------
// Slash command handler — opens the modal
// ---------------------------------------------------------------------------

async function execute(interaction) {
  const modal = new ModalBuilder()
    .setCustomId('purchase-request-modal')
    .setTitle('Equipment Purchase Request');

  const itemNameInput = new TextInputBuilder()
    .setCustomId('pr-item-name')
    .setLabel('Item Name')
    .setStyle(TextInputStyle.Short)
    .setMaxLength(255)
    .setRequired(true);

  const descriptionInput = new TextInputBuilder()
    .setCustomId('pr-description')
    .setLabel('Description of Need')
    .setStyle(TextInputStyle.Paragraph)
    .setMaxLength(1000)
    .setRequired(true);

  const departmentInput = new TextInputBuilder()
    .setCustomId('pr-department')
    .setLabel('Department / Class')
    .setStyle(TextInputStyle.Short)
    .setMaxLength(255)
    .setRequired(true);

  const urlInput = new TextInputBuilder()
    .setCustomId('pr-url')
    .setLabel('URL to Item (optional)')
    .setStyle(TextInputStyle.Short)
    .setMaxLength(500)
    .setRequired(false);

  const quantityInput = new TextInputBuilder()
    .setCustomId('pr-quantity')
    .setLabel('Quantity (optional)')
    .setStyle(TextInputStyle.Short)
    .setMaxLength(10)
    .setPlaceholder('1')
    .setRequired(false);

  // Each ActionRow can hold exactly one TextInput in a modal
  modal.addComponents(
    new ActionRowBuilder().addComponents(itemNameInput),
    new ActionRowBuilder().addComponents(descriptionInput),
    new ActionRowBuilder().addComponents(departmentInput),
    new ActionRowBuilder().addComponents(urlInput),
    new ActionRowBuilder().addComponents(quantityInput)
  );

  await interaction.showModal(modal);
}

// ---------------------------------------------------------------------------
// Modal submit handler
// ---------------------------------------------------------------------------

async function modalSubmit(interaction, client) {
  await interaction.deferReply({ flags: 64 }); // ephemeral

  // --- Extract field values ---
  const itemName = interaction.fields.getTextInputValue('pr-item-name').trim();
  const description = interaction.fields.getTextInputValue('pr-description').trim();
  const department = interaction.fields.getTextInputValue('pr-department').trim();
  const rawUrl = interaction.fields.getTextInputValue('pr-url').trim();
  const rawQty = interaction.fields.getTextInputValue('pr-quantity').trim();

  // --- Validate quantity ---
  let quantity = 1;
  if (rawQty !== '') {
    const parsed = parseInt(rawQty, 10);
    if (isNaN(parsed) || parsed < 1 || String(parsed) !== rawQty) {
      await interaction.editReply({
        content: 'Quantity must be a positive whole number (e.g. 1, 2, 3).',
      });
      return;
    }
    quantity = parsed;
  }

  // --- Validate URL ---
  let itemUrl = null;
  if (rawUrl !== '') {
    try {
      const parsed = new URL(rawUrl);
      if (!['http:', 'https:'].includes(parsed.protocol)) {
        throw new Error('Invalid protocol');
      }
      itemUrl = rawUrl;
    } catch {
      await interaction.editReply({
        content: 'The URL you provided does not appear to be valid. Please use a full URL starting with http:// or https://',
      });
      return;
    }
  }

  // --- Look up linked SnipeScheduler user ---
  let linked = null;
  try {
    const [rows] = await pool.execute(
      'SELECT user_id, email, name FROM users WHERE discord_user_id = ? LIMIT 1',
      [interaction.user.id]
    );
    if (rows.length > 0) {
      linked = rows[0];
    }
  } catch (err) {
    console.error('DB error looking up linked user:', err);
    // Non-fatal — continue without linking
  }

  // --- Detect faculty status ---
  let isFaculty = false;
  try {
    const guild = await client.guilds.fetch(process.env.FACULTY_SERVER_ID);
    const member = await guild.members.fetch(interaction.user.id);
    isFaculty = !!member;
  } catch {
    // User is not in the faculty server or fetch failed — not faculty
    isFaculty = false;
  }

  // --- Insert purchase request ---
  let requestId;
  try {
    const [result] = await pool.execute(
      `INSERT INTO purchase_requests
        (submitter_name, submitter_email, user_id, discord_user_id, source,
         item_name, description, department, item_url, quantity,
         is_faculty, importance, status, created_at, updated_at)
       VALUES (?, ?, ?, ?, 'discord', ?, ?, ?, ?, ?, ?, ?, 'open', NOW(), NOW())`,
      [
        linked?.name ?? interaction.user.displayName,
        linked?.email ?? null,
        linked?.user_id ?? null,
        interaction.user.id,
        itemName,
        description,
        department,
        itemUrl,
        quantity,
        isFaculty ? 1 : 0,
        isFaculty ? 'medium' : null,
      ]
    );
    requestId = result.insertId;
  } catch (err) {
    console.error('DB error inserting purchase request:', err);
    await interaction.editReply({
      content: 'Sorry, there was a database error saving your request. Please try again later.',
    });
    return;
  }

  // --- Build confirmation embed ---
  const embed = new EmbedBuilder()
    .setColor(0x22c55e)
    .setTitle('Purchase Request Submitted')
    .addFields(
      { name: 'Item', value: itemName, inline: true },
      { name: 'Quantity', value: String(quantity), inline: true },
      { name: 'Department / Class', value: department, inline: false },
      { name: 'Request ID', value: `#${requestId}`, inline: true }
    )
    .setTimestamp();

  if (itemUrl) {
    embed.addFields({ name: 'Link', value: itemUrl, inline: false });
  }

  const footerParts = [];
  if (isFaculty) footerParts.push('Faculty');
  if (footerParts.length > 0) {
    embed.setFooter({ text: footerParts.join(' · ') });
  }

  const replyContent = [];
  if (!linked) {
    replyContent.push(
      '-# Tip: Link your Discord account in SnipeScheduler to track your requests online.'
    );
  }

  await interaction.editReply({
    content: replyContent.length > 0 ? replyContent.join('\n') : null,
    embeds: [embed],
  });
}

// ---------------------------------------------------------------------------

module.exports = { data, execute, modalSubmit };
