'use strict';

require('dotenv').config();
const { Client, GatewayIntentBits, Collection } = require('discord.js');

const purchaseRequest = require('./commands/purchase-request');
const myRequests = require('./commands/my-requests');

const client = new Client({
  intents: [
    GatewayIntentBits.Guilds,
    GatewayIntentBits.GuildMembers, // privileged — needed for faculty detection
  ],
});

// Attach commands to client for easy access inside handlers
client.commands = new Collection();
client.commands.set(purchaseRequest.data.name, purchaseRequest);
client.commands.set(myRequests.data.name, myRequests);

client.once('ready', () => {
  console.log(`Logged in as ${client.user.tag}`);
});

client.on('interactionCreate', async (interaction) => {
  // Slash commands
  if (interaction.isChatInputCommand()) {
    const command = client.commands.get(interaction.commandName);
    if (!command) return;

    try {
      await command.execute(interaction, client);
    } catch (err) {
      console.error(`Error executing /${interaction.commandName}:`, err);
      const payload = {
        content: 'An unexpected error occurred. Please try again later.',
        ephemeral: true,
      };
      if (interaction.replied || interaction.deferred) {
        await interaction.followUp(payload).catch(() => {});
      } else {
        await interaction.reply(payload).catch(() => {});
      }
    }
    return;
  }

  // Modal submissions
  if (interaction.isModalSubmit()) {
    if (interaction.customId === 'purchase-request-modal') {
      try {
        await purchaseRequest.modalSubmit(interaction, client);
      } catch (err) {
        console.error('Error handling purchase-request-modal submit:', err);
        const payload = {
          content: 'An unexpected error occurred while saving your request. Please try again.',
          ephemeral: true,
        };
        if (interaction.replied || interaction.deferred) {
          await interaction.followUp(payload).catch(() => {});
        } else {
          await interaction.reply(payload).catch(() => {});
        }
      }
    }
    return;
  }
});

client.login(process.env.BOT_TOKEN).catch((err) => {
  console.error('Failed to log in:', err);
  process.exit(1);
});
