{block name="content"}
<div class="content-container">
  <table class="table table-striped table-condensed table-bordered dataTable" id="incidents">
  <thead>
    <tr role='row'>
      <th class="span-2">{t}Service{/t}</th>
      <th class="span-1">{t}Status{/t}</th>
      <th class="span-1">{t}Duration{/t}</th>
    </tr>
  </thead>
  <tbody>
      {foreach from=$issues item=d key=k}
            <tr>
                <td>{$d.description}</td>
                <td>{$d.state}</td>
                <td>{$d.start_time}</td>
            </tr>
      {/foreach}
      
      
  </tbody>
  </table>
</div>
{/block}