'use strict';

require('dotenv').config();
const { REST, Routes } = require('discord.js');

const purchaseRequest = require('./commands/purchase-request');
const myRequests = require('./commands/my-requests');

const commands = [
  purchaseRequest.data.toJSON(),
  myRequests.data.toJSON(),
];

const rest = new REST({ version: '10' }).setToken(process.env.BOT_TOKEN);

const guildIds = (process.env.GUILD_IDS || '')
  .split(',')
  .map((id) => id.trim())
  .filter(Boolean);

(async () => {
  try {
    if (guildIds.length === 0) {
      // Register globally (takes up to 1 hour to propagate)
      console.log(`Registering ${commands.length} global command(s)...`);
      const data = await rest.put(
        Routes.applicationCommands(process.env.CLIENT_ID),
        { body: commands }
      );
      console.log(`Successfully registered ${data.length} global command(s).`);
    } else {
      // Register per guild (instant)
      for (const guildId of guildIds) {
        console.log(`Registering ${commands.length} command(s) in guild ${guildId}...`);
        const data = await rest.put(
          Routes.applicationGuildCommands(process.env.CLIENT_ID, guildId),
          { body: commands }
        );
        console.log(`  Registered ${data.length} command(s).`);
      }
    }
  } catch (err) {
    console.error('Failed to deploy commands:', err);
    process.exit(1);
  }
})();
