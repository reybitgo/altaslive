<?php

/**
 * @file   views/member/genealogy.php
 * @brief  Member genealogy UI
 */
?>
<?php $pageTitle = $view === 'referral' ? 'Referral Network' : 'Binary Tree'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_member.php'; ?>

<!-- D3.js CDN -->
<script src="https://d3js.org/d3.v7.min.js"></script>

<style>
  /* D3 Tree Container Styles */
  #treeContainer {
    width: 100%;
    min-height: 400px;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-radius: 12px;
    position: relative;
    overflow: hidden;
  }

  /* Responsive SVG container */
  .svg-container {
    width: 100%;
    height: 100%;
    display: flex;
    justify-content: center;
    align-items: flex-start;
  }

  .svg-container svg {
    width: 100%;
    height: auto;
    max-height: 80vh;
  }

  /* Node Styles - Rounded Rectangle */
  .node rect {
    cursor: pointer;
    transition: all 0.3s ease;
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.1));
  }

  .node rect:hover {
    filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.15));
    transform: scale(1.02);
  }

  .node.active rect {
    fill: #12a05c;
    stroke: #0d8a4d;
    stroke-width: 2px;
  }

  .node.suspended rect {
    fill: #e03434;
    stroke: #c22a2a;
    stroke-width: 2px;
  }

  .node.empty rect {
    fill: #f8fafc;
    stroke: #cbd5e1;
    stroke-width: 2px;
    stroke-dasharray: 5, 5;
  }

  .node.pending rect {
    fill: #f59e0b;
    stroke: #d97706;
    stroke-width: 2px;
  }

  /* Node Text */
  .node text {
    font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
    font-size: 11px;
    font-weight: 600;
    fill: white;
    text-anchor: middle;
    pointer-events: none;
  }

  .node.empty text {
    fill: #64748b;
    font-size: 10px;
  }

  /* Count badges below nodes */
  .node .count-text {
    font-size: 9px;
    fill: #64748b;
    font-weight: 500;
  }

  /* Links */
  .link {
    fill: none;
    stroke: #cbd5e1;
    stroke-width: 2px;
    transition: stroke 0.3s ease;
  }

  .link:hover {
    stroke: #94a3b8;
  }

  /* Empty slot indicator */
  .empty-indicator {
    fill: #e2e8f0;
    stroke: #cbd5e1;
    stroke-width: 1.5px;
  }

  /* Tooltip */
  #treeTooltip {
    position: fixed;
    background: #1a2035;
    color: #fff;
    border-radius: 10px;
    padding: 12px 16px;
    font-size: 0.8rem;
    pointer-events: none;
    z-index: 9999;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
    min-width: 180px;
    opacity: 0;
    transition: opacity 0.2s ease;
  }

  #treeTooltip.visible {
    opacity: 1;
  }

  /* Controls */
  .tree-controls {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }

  .tree-controls button {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    background: white;
    color: #475569;
    cursor: pointer;
    transition: all 0.2s ease;
  }

  .tree-controls button:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
  }

  /* Mobile optimizations */
  @media (max-width: 768px) {
    #treeContainer {
      min-height: 350px;
    }

    .node text {
      font-size: 9px;
    }

    .node .count-text {
      font-size: 8px;
    }

    .tree-controls button {
      width: 40px;
      height: 40px;
      font-size: 1.1rem;
    }

    #treeTooltip {
      font-size: 0.75rem;
      padding: 10px 12px;
      min-width: 150px;
    }
  }

  /* Loading state */
  #treeLoading {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 3rem 1rem;
    color: #6b7a99;
    gap: 0.5rem;
    font-size: 0.9rem;
  }

  /* Legend */
  .tree-legend {
    display: flex;
    gap: 1.5rem;
    flex-wrap: wrap;
    font-size: 0.75rem;
    color: #64748b;
    align-items: center;
  }

  .legend-item {
    display: flex;
    align-items: center;
    gap: 0.4rem;
  }

  .legend-dot {
    width: 12px;
    height: 12px;
    border-radius: 3px;
  }

  .legend-dot.active {
    background: #12a05c;
  }

  .legend-dot.suspended {
    background: #e03434;
  }

  .legend-dot.empty {
    background: #f8fafc;
    border: 2px dashed #cbd5e1;
  }

  .legend-dot.pending {
    background: #f59e0b;
  }
</style>

<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
      <ul class="nav nav-pills mb-0">
        <li class="nav-item"><a class="nav-link <?= $view !== 'referral' ? 'active' : '' ?>" href="<?= APP_URL ?>/?page=genealogy&view=binary">🌳 Binary Tree</a></li>
        <li class="nav-item"><a class="nav-link <?= $view === 'referral' ? 'active' : '' ?>" href="<?= APP_URL ?>/?page=genealogy&view=referral">👥 Referral Network</a></li>
      </ul>
      <div class="ms-auto" style="min-width:220px;max-width:360px;width:100%;">
        <div class="input-group input-group-sm">
          <span class="input-group-text">🔗</span>
          <input type="text" class="form-control font-mono" id="refLink" readonly
            value="<?= APP_URL ?>/?page=register&sponsor=<?= urlencode($user['username']) ?>&ref=1">
          <button class="btn btn-outline-secondary" type="button" onclick="copyRefLink()" title="Copy">
            📋
          </button>
        </div>
      </div>
    </div>

    <script>
    function copyRefLink() {
      const el = document.getElementById('refLink');
      el.select();
      el.setSelectionRange(0, 99999);
      navigator.clipboard.writeText(el.value).then(() => {
        const btn = el.nextElementSibling;
        const old = btn.textContent;
        btn.textContent = '✓';
        setTimeout(() => btn.textContent = old, 1500);
      });
    }
    </script>

    <?php if ($view !== 'referral'): ?>
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
          <span class="card-title">🌳 Binary Tree</span>
          <div class="tree-controls">
            <button onclick="resetTree()" title="Reset View">⟲</button>
            <button onclick="zoomTree(0.8)" title="Zoom Out">−</button>
            <button onclick="zoomTree(1.25)" title="Zoom In">+</button>
            <button onclick="expandAll()" title="Expand All">⤢</button>
            <button onclick="collapseAll()" title="Collapse All">⤡</button>
          </div>
        </div>

        <div id="treeContainer">
          <div id="treeLoading">
            <div class="spinner-border spinner-border-sm text-primary"></div>
            <span>Loading tree…</span>
          </div>
          <div id="svgWrapper" class="svg-container"></div>
        </div>

        <div id="treeTooltip"></div>

        <div class="card-footer">
          <div class="tree-legend">
            <div class="legend-item">
              <div class="legend-dot active"></div>
              <span>Active</span>
            </div>
            <div class="legend-item">
              <div class="legend-dot suspended"></div>
              <span>Suspended</span>
            </div>
            <div class="legend-item">
              <div class="legend-dot pending"></div>
              <span>Pending</span>
            </div>
            <span class="ms-auto text-muted">Click nodes to expand/collapse • Drag to pan</span>
          </div>
        </div>
      </div>

    <?php elseif (setting('indirect_referral_enabled', '1') === '1'): ?>
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span class="card-title">👥 Referral Network (10 Levels)</span>
          <span class="badge bg-secondary-subtle text-secondary"><?= count($indirect) ?> members</span>
        </div>
        <div class="card-body p-0">
          <?php if (empty($indirect)): ?>
            <div class="text-center py-5 text-muted">
              <div style="font-size:2.5rem;">👥</div>
              <p class="mt-2 mb-0">You haven't referred anyone yet.</p>
            </div>
            <?php else:
            $grouped = [];
            foreach ($indirect as $m) $grouped[$m['level']][] = $m;
            foreach ($grouped as $lvl => $members): ?>
              <div>
                <div class="d-flex align-items-center gap-2 px-3 py-2 cursor-pointer border-bottom"
                  style="background:#f8fafd;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);"
                  onclick="toggleRef(<?= $lvl ?>)">
                  <span>Level <?= $lvl ?></span>
                  <span class="badge bg-primary-subtle text-primary"><?= count($members) ?></span>
                  <span id="refArrow<?= $lvl ?>" class="ms-auto">▼</span>
                </div>
                <div id="refLevel<?= $lvl ?>">
                  <?php foreach ($members as $m): ?>
                    <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom">
                      <div class="user-avatar"><?= strtoupper(substr($m['username'], 0, 1)) ?></div>
                      <div class="flex-grow-1">
                        <div class="fw-600" style="font-size:.825rem;">@<?= e($m['username']) ?><?= $m['full_name'] ? ' — ' . e($m['full_name']) : '' ?></div>
                        <div class="text-muted" style="font-size:.72rem;"><?= e($m['package_name'] ?? 'Member') ?> · Joined <?= fmt_date($m['joined_at']) ?></div>
                      </div>
                      <span class="badge <?= $m['status'] === 'active' ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' ?>"><?= ucfirst($m['status']) ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
          <?php endforeach;
          endif; ?>
        </div>
      </div>
    <?php else: ?>
      <!-- Indirect disabled — show direct referrals table instead -->
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span class="card-title">👥 Direct Referrals</span>
          <span class="badge bg-secondary-subtle text-secondary"><?= $direct['total'] ?? 0 ?> members</span>
        </div>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th>#</th>
                <th>Username</th>
                <th>Package</th>
                <th>Joined</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($direct['data'])): ?>
                <tr>
                  <td colspan="5" class="text-center py-5 text-muted">
                    <div style="font-size:2.5rem;">👥</div>
                    <p class="mt-2 mb-0">You haven't referred anyone yet.</p>
                  </td>
                </tr>
                <?php else: foreach ($direct['data'] as $i => $m): ?>
                  <tr>
                    <td class="td-muted" style="font-size:.7rem;"><?= ($direct['page'] - 1) * 20 + $i + 1 ?></td>
                    <td class="fw-bold">@<?= e($m['username']) ?></td>
                    <td><span class="badge bg-primary-subtle text-primary"><?= e($m['package_name'] ?? '—') ?></span></td>
                    <td class="td-muted" style="font-size:.75rem;"><?= fmt_date($m['joined_at']) ?></td>
                    <td>
                      <?php $b = $m['status'] === 'active' ? 'bg-success-subtle text-success' : ($m['status'] === 'suspended' ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning'); ?>
                      <span class="badge <?= $b ?>"><?= ucfirst($m['status']) ?></span>
                    </td>
                  </tr>
                <?php endforeach;
                endif; ?>
            </tbody>
          </table>
        </div>
        <?php if (($direct['total_pages'] ?? 1) > 1): ?>
          <div class="card-footer"><?= pagination_links($direct, APP_URL . '/?page=genealogy&view=referral') ?></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($view !== 'referral'): ?>
  <script>
    // D3.js Binary Tree Visualization
    const API_URL = '<?= APP_URL ?>/?page=api_binary_tree&root=<?= Auth::id() ?>';
    let svg, g, tree, root, zoom;
    let currentScale = 1;
    let currentTranslate = [0, 0];

    // Node dimensions - proportional and responsive
    const nodeWidth = 140;
    const nodeHeight = 54;
    const nodeRadius = 8; // Rounded corners
    const levelHeight = 110; // Vertical spacing between levels
    const siblingSpacing = 170; // Horizontal spacing between siblings

    // Mobile detection for responsive sizing
    const isMobile = window.matchMedia('(max-width: 768px)').matches;
    const responsiveNodeWidth = isMobile ? 110 : nodeWidth;
    const responsiveNodeHeight = isMobile ? 48 : nodeHeight;
    const responsiveLevelHeight = isMobile ? 90 : levelHeight;
    const responsiveSiblingSpacing = isMobile ? 130 : siblingSpacing;

    async function loadTree() {
      const loader = document.getElementById('treeLoading');
      const wrapper = document.getElementById('svgWrapper');

      try {
        const res = await fetch(API_URL + '&depth=4');
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();

        loader.style.display = 'none';
        initD3Tree(data, wrapper);
      } catch (e) {
        loader.innerHTML = `<span style="color:#e03434;">⚠ Could not load tree. ${e.message}</span>`;
      }
    }

    // Hierarchy accessor — reusable for lazy loading
    const treeAccessor = d => {
      const children = [];
      if (d.left) children.push(d.left);
      if (d.right) children.push(d.right);
      // Add empty slot indicators if missing children (for visualization)
      if (!d.left && d.depth < 3) {
        children.push({
          username: 'Empty',
          status: 'empty',
          isPlaceholder: true,
          parent: d,
          depth: (d.depth || 0) + 1
        });
      }
      if (!d.right && d.depth < 3) {
        children.push({
          username: 'Empty',
          status: 'empty',
          isPlaceholder: true,
          parent: d,
          depth: (d.depth || 0) + 1
        });
      }
      return children.length > 0 ? children : null;
    };

    function initD3Tree(data, container) {
      // Clear previous
      container.innerHTML = '';

      // Get container dimensions
      const containerWidth = container.clientWidth || 800;
      const containerHeight = Math.max(window.innerHeight * 0.6, 500);

      // Create SVG with viewBox for responsiveness
      svg = d3.select(container)
        .append('svg')
        .attr('width', '100%')
        .attr('height', containerHeight)
        .attr('viewBox', `0 0 ${containerWidth} ${containerHeight}`)
        .attr('preserveAspectRatio', 'xMidYMid meet')
        .style('font-family', 'Plus Jakarta Sans, sans-serif');

      // Add zoom behavior
      zoom = d3.zoom()
        .scaleExtent([0.3, 3])
        .on('zoom', (event) => {
          g.attr('transform', event.transform);
          currentScale = event.transform.k;
          currentTranslate = [event.transform.x, event.transform.y];
        });

      svg.call(zoom);

      // Create main group for tree
      g = svg.append('g');

      // Convert data to hierarchy
      root = d3.hierarchy(data, treeAccessor);

      // Store original children for expand/collapse
      root.descendants().forEach(d => {
        d._children = d.children;
        // Collapse after level 1 initially for cleaner view
        if (d.depth > 1) {
          d.children = null;
        }
      });

      // Create tree layout with nodeSize for consistent spacing
      tree = d3.tree()
        .nodeSize([responsiveSiblingSpacing, responsiveLevelHeight])
        .separation((a, b) => (a.parent == b.parent ? 1 : 1.5));

      // Initial render - center the root node at top
      update(root);
      centerRoot();
    }

    function update(source) {
      // Compute new tree layout
      const treeData = tree(root);
      const nodes = treeData.descendants();
      const links = treeData.links();

      // Normalize for fixed-depth (vertical layout)
      nodes.forEach(d => {
        d.y = d.depth * responsiveLevelHeight + 60; // 60px padding from top
      });

      // ****************** Nodes ******************
      const node = g.selectAll('g.node')
        .data(nodes, d => d.id || (d.id = ++i));

      // Enter new nodes at parent's previous position
      const nodeEnter = node.enter().append('g')
        .attr('class', d => `node ${d.data.status || 'active'} ${d.data.isPlaceholder ? 'empty' : ''}`)
        .attr('transform', d => `translate(${source.x0 || 0},${source.y0 || 0})`)
        .on('click', (event, d) => {
          // Skip click on empty-slot placeholders
          if (!d.data.isPlaceholder) {
            toggle(d);
          }
        })
        .on('mouseover', (event, d) => showTooltip(event, d))
        .on('mouseout', hideTooltip);

      // Add rounded rectangle
      nodeEnter.append('rect')
        .attr('width', d => d.data.isPlaceholder ? responsiveNodeWidth * 0.8 : responsiveNodeWidth)
        .attr('height', d => d.data.isPlaceholder ? responsiveNodeHeight * 0.8 : responsiveNodeHeight)
        .attr('x', d => d.data.isPlaceholder ? -(responsiveNodeWidth * 0.4) : -responsiveNodeWidth / 2)
        .attr('y', d => d.data.isPlaceholder ? -(responsiveNodeHeight * 0.4) : -responsiveNodeHeight / 2)
        .attr('rx', nodeRadius)
        .attr('ry', nodeRadius);

      // Add username text (multi-line wrap for long names)
      nodeEnter.append('text')
        .attr('text-anchor', 'middle')
        .each(function(d) {
          const el = d3.select(this);
          const name = d.data.username || 'Unknown';
          const maxChars = isMobile ? 11 : 14;
          const lineHeight = isMobile ? 11 : 12;

          let lines = [];
          if (name.length <= maxChars) {
            lines = [name];
          } else {
            lines = [name.slice(0, maxChars), name.slice(maxChars)];
            if (lines[1].length > maxChars) {
              lines[1] = lines[1].slice(0, maxChars - 1) + '…';
            }
          }

          const startY = -(lines.length - 1) * lineHeight / 2;
          lines.forEach((line, i) => {
            el.append('tspan')
              .attr('x', 0)
              .attr('dy', i === 0 ? startY + 'px' : lineHeight + 'px')
              .text(line);
          });
        });

      // Add expand/collapse circle for nodes with children (loaded or not-yet-loaded)
      nodeEnter.filter(d => !d.data.isPlaceholder).append('circle')
        .attr('class', 'toggle-circle')
        .attr('r', d => d.data.hasMore ? 11 : 10)
        .attr('cy', d => responsiveNodeHeight / 2 + 12)
        .style('fill', '#fff')
        .style('stroke', d => d.data.hasMore ? '#3b6ff0' : '#94a3b8')
        .style('stroke-width', d => d.data.hasMore ? 2 : 1.5)
        .style('cursor', 'pointer')
        .style('display', d => (d.children || d._children || d.data.hasMore) ? null : 'none');

      // Add + / − text inside the toggle circle (perfectly centred)
      nodeEnter.filter(d => !d.data.isPlaceholder).append('text')
        .attr('class', 'toggle-text')
        .attr('x', 0)
        .attr('y', d => responsiveNodeHeight / 2 + 12)
        .attr('font-size', d => d.data.hasMore ? '22px' : '19px')
        .attr('font-weight', '700')
        .attr('text-anchor', 'middle')
        .attr('dominant-baseline', 'middle')
        .style('fill', d => d.data.hasMore ? '#3b6ff0' : '#64748b')
        .style('pointer-events', 'none')
        .style('display', d => (d.children || d._children || d.data.hasMore) ? null : 'none')
        .text(d => d.children ? '−' : '+');

      // Add count badges for non-empty nodes — hidden if leaf (no children at all)
      nodeEnter.filter(d => !d.data.isPlaceholder).append('text')
        .attr('class', 'count-text')
        .attr('dy', '1.8em')
        .style('fill', d => d.children ? 'rgba(255,255,255,0.8)' : '#64748b')
        .style('display', d => (d.children || d._children) ? null : 'none')
        .text(d => `L:${d.data.left_count || 0} R:${d.data.right_count || 0}`);

      // UPDATE
      const nodeUpdate = node.merge(nodeEnter).transition()
        .duration(500)
        .attr('transform', d => `translate(${d.x},${d.y})`);

      // Update rectangle dimensions on resize
      nodeUpdate.select('rect')
        .attr('width', d => d.data.isPlaceholder ? responsiveNodeWidth * 0.8 : responsiveNodeWidth)
        .attr('height', d => d.data.isPlaceholder ? responsiveNodeHeight * 0.8 : responsiveNodeHeight)
        .attr('x', d => d.data.isPlaceholder ? -(responsiveNodeWidth * 0.4) : -responsiveNodeWidth / 2)
        .attr('y', d => d.data.isPlaceholder ? -(responsiveNodeHeight * 0.4) : -responsiveNodeHeight / 2);

      // Update toggle circle visibility, colour & size
      nodeUpdate.select('.toggle-circle')
        .attr('r', d => d.data.hasMore ? 11 : 10)
        .attr('cy', d => responsiveNodeHeight / 2 + 12)
        .style('stroke', d => d.data.hasMore ? '#3b6ff0' : '#94a3b8')
        .style('stroke-width', d => d.data.hasMore ? 2 : 1.5)
        .style('display', d => (d.children || d._children || d.data.hasMore) ? null : 'none');

      // Update toggle text (+ / −)
      nodeUpdate.select('.toggle-text')
        .attr('y', d => responsiveNodeHeight / 2 + 12)
        .attr('font-size', d => d.data.hasMore ? '22px' : '19px')
        .style('fill', d => d.data.hasMore ? '#3b6ff0' : '#64748b')
        .style('display', d => (d.children || d._children || d.data.hasMore) ? null : 'none')
        .text(d => d.children ? '−' : '+');

      // Update count-text colour: white when expanded (has visible children), muted when collapsed/leaf
      // Also hide entirely if node has no children at all (free slot indicator)
      nodeUpdate.select('.count-text')
        .style('fill', d => d.children ? 'rgba(255,255,255,0.8)' : '#64748b')
        .style('display', d => (d.children || d._children) ? null : 'none');

      // Exit nodes
      const nodeExit = node.exit().transition()
        .duration(500)
        .attr('transform', d => `translate(${source.x},${source.y})`)
        .remove();

      nodeExit.select('rect').attr('r', 1e-6);
      nodeExit.select('text').style('fill-opacity', 1e-6);

      // ****************** Links ******************
      const link = g.selectAll('path.link')
        .data(links, d => d.target.id);

      // Enter new links at parent's previous position
      const linkEnter = link.enter().insert('path', 'g')
        .attr('class', 'link')
        .attr('d', d => {
          const o = {
            x: source.x0 || 0,
            y: source.y0 || 0
          };
          return diagonal(o, o);
        });

      // UPDATE
      const linkUpdate = link.merge(linkEnter).transition()
        .duration(500)
        .attr('d', d => diagonal(d.source, d.target));

      // Exit links
      link.exit().transition()
        .duration(500)
        .attr('d', d => {
          const o = {
            x: source.x,
            y: source.y
          };
          return diagonal(o, o);
        })
        .remove();

      // Store old positions for transition
      nodes.forEach(d => {
        d.x0 = d.x;
        d.y0 = d.y;
      });
    }

    // Curved path generator (vertical tree)
    function diagonal(s, d) {
      return `M ${s.x} ${s.y + responsiveNodeHeight/2}
          C ${s.x} ${(s.y + d.y) / 2},
            ${d.x} ${(s.y + d.y) / 2},
            ${d.x} ${d.y - responsiveNodeHeight/2}`;
    }

    // Lazy-load deeper levels when a node with hasMore is clicked
    async function loadChildren(d) {
      if (d.data.isLoading) return;
      d.data.isLoading = true;

      try {
        const res = await fetch(`${'<?= APP_URL ?>/?page=api_binary_tree&root='}${d.data.id}&depth=4`);
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const subtree = await res.json();

        // Build a temporary hierarchy to get properly-wired children
        const tempRoot = d3.hierarchy(subtree, treeAccessor);

        // Reparent the loaded children to the clicked node
        if (tempRoot.children) {
          tempRoot.children.forEach(child => {
            child.parent = d;
            // Recursively set correct depths in the main tree
            function setDepth(node, depth) {
              node.depth = depth;
              const descendants = node.children || node._children;
              if (descendants) {
                descendants.forEach(c => setDepth(c, depth + 1));
              }
            }
            setDepth(child, d.depth + 1);
          });
          d._children = tempRoot.children;
        }
        d.data.hasMore = false;
      } catch (e) {
        console.error('Failed to load children', e);
        alert('Could not load deeper levels. Please try again.');
      } finally {
        d.data.isLoading = false;
      }
    }

    // Toggle children on click
    async function toggle(d) {
      // Lazy-load if children haven't been fetched yet
      if (d.data.hasMore && !d._children && !d.children) {
        await loadChildren(d);
      }

      if (d.children) {
        d._children = d.children;
        d.children = null;
      } else {
        d.children = d._children;
        d._children = null;
      }
      update(d);
      centerNode(d);
    }

    // Center tree on root node at top
    function centerRoot() {
      const svgWidth = svg.node().clientWidth || 800;
      const svgHeight = svg.node().clientHeight || 500;

      // Calculate transform to center root at top
      const x = svgWidth / 2;
      const y = 60; // Padding from top
      const k = isMobile ? 0.7 : 1;

      svg.transition().duration(750).call(
        zoom.transform,
        d3.zoomIdentity.translate(x, y).scale(k)
      );
    }

    // Center on specific node
    function centerNode(d) {
      const svgWidth = svg.node().clientWidth || 800;
      const svgHeight = svg.node().clientHeight || 500;

      const t = d3.zoomIdentity
        .translate(svgWidth / 2 - d.x * currentScale, 80 - d.y * currentScale)
        .scale(currentScale);

      svg.transition().duration(750).call(zoom.transform, t);
    }

    // Tooltip functions
    function showTooltip(event, d) {
      const tooltip = document.getElementById('treeTooltip');
      const data = d.data;

      let html = `<div style="font-weight:700;margin-bottom:6px;font-size:0.9rem;">@${data.username || 'Unknown'}</div>`;

      if (!data.isPlaceholder) {
        html += `<div style="color:rgba(255,255,255,0.7);font-size:0.75rem;line-height:1.4;">`;
        html += `${data.package || 'Member'} · ${data.joined || '—'}<br>`;
        html += `Left: ${data.left_count || 0} · Right: ${data.right_count || 0}<br>`;
        const statusColor = data.status==='active'?'#4ade80':data.status==='pending'?'#fbbf24':'#f87171';
        html += `Status: <span style="color:${statusColor};font-weight:600;">${data.status || 'active'}</span>`;
        html += `</div>`;
      } else {
        html += `<div style="color:rgba(255,255,255,0.7);font-size:0.75rem;">Open slot available</div>`;
      }

      tooltip.innerHTML = html;
      tooltip.classList.add('visible');

      // Position tooltip with boundary checks
      const tooltipRect = tooltip.getBoundingClientRect();
      let left = event.clientX + 15;
      let top = event.clientY - 10;

      if (left + tooltipRect.width > window.innerWidth) {
        left = event.clientX - tooltipRect.width - 15;
      }
      if (top + tooltipRect.height > window.innerHeight) {
        top = event.clientY - tooltipRect.height - 10;
      }

      tooltip.style.left = left + 'px';
      tooltip.style.top = top + 'px';
    }

    function hideTooltip() {
      document.getElementById('treeTooltip').classList.remove('visible');
    }

    // Control functions
    function resetTree() {
      centerRoot();
    }

    function zoomTree(factor) {
      const newScale = Math.max(0.3, Math.min(3, currentScale * factor));
      svg.transition().duration(300).call(
        zoom.scaleTo,
        newScale
      );
    }

    function expandAll() {
      root.descendants().forEach(d => {
        if (d._children) {
          d.children = d._children;
          d._children = null;
        }
      });
      update(root);
      centerRoot();
    }

    function collapseAll() {
      root.descendants().forEach(d => {
        if (d.depth > 0 && d.children) {
          d._children = d.children;
          d.children = null;
        }
      });
      update(root);
      centerRoot();
    }

    // Counter for node IDs
    let i = 0;

    // Handle window resize
    let resizeTimeout;
    window.addEventListener('resize', () => {
      clearTimeout(resizeTimeout);
      resizeTimeout = setTimeout(() => {
        const newIsMobile = window.matchMedia('(max-width: 768px)').matches;
        if (newIsMobile !== isMobile) {
          location.reload();
        } else if (svg) {
          const container = document.getElementById('svgWrapper');
          const newHeight = Math.max(window.innerHeight * 0.6, 500);
          svg.attr('height', newHeight);
          centerRoot();
        }
      }, 250);
    });

    // Initialize
    loadTree();
  </script>
<?php endif; ?>

<script>
  function toggleRef(lvl) {
    const el = document.getElementById('refLevel' + lvl);
    const arrow = document.getElementById('refArrow' + lvl);
    if (!el) return;
    const hidden = el.style.display === 'none';
    el.style.display = hidden ? 'block' : 'none';
    arrow.textContent = hidden ? '▼' : '▶';
  }
</script>

<?php require 'views/partials/footer.php'; ?>